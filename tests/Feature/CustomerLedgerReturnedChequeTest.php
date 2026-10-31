<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\ChequePaymentService;
use App\Services\CustomerLedgerService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('returned customer sale cheque shows as returned and is not credited in ledger and pdf', function () {
    $customer = Customer::query()->create(['name' => 'Mobile communication', 'due_balance' => 50000]);
    $sale = $customer->sales()->create([
        'invoice_no' => 'INV-1817',
        'date' => '2026-05-14',
        'grand_total' => 62550,
        'paid_amount' => 12550,
        'due_amount' => 50000,
        'payment_status' => 'partial',
    ]);

    $cash = $sale->payments()->create([
        'amount' => 12550,
        'payment_method' => 'cash',
        'date' => '2026-05-14',
        'reference' => 'PAY-1563',
    ]);

    $cheque = $sale->payments()->create([
        'amount' => 50000,
        'payment_method' => 'cheque',
        'date' => '2026-05-14',
        'reference' => '375361',
        'cheque_no' => '375361',
        'cheque_bank' => 'BOC',
        'cheque_date' => '2026-06-26',
        'cheque_status' => 'returned',
    ]);

    $service = app(CustomerLedgerService::class);
    $rows = $service->rows($customer);

    $chequeRow = $rows->firstWhere('id', $cheque->id);
    expect($chequeRow)->not->toBeNull()
        ->and($chequeRow['badge'])->toBe('Returned Cheque')
        ->and($chequeRow['cheque_status'])->toBe('returned')
        ->and($chequeRow['description'])->toContain('Cheque returned for bill')
        ->and($chequeRow['credit'])->toBe(0.0)
        ->and($chequeRow['amount'])->toBe(50000.0)
        ->and($chequeRow['cheque_no'])->toBe('375361')
        ->and($chequeRow['cheque_bank'])->toBe('BOC');

    $component = Livewire::test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Returned Cheque')
        ->assertSee('375361')
        ->assertSee('BOC')
        ->assertSee('RETURNED')
        ->assertDontSee('- Rs 50,000.00');

    $response = $this->get(route('customers.ledger.pdf', $customer));
    $response->assertOk();
});

test('returned customer account cheque shows as returned and is not credited', function () {
    $customer = Customer::query()->create(['name' => 'Account Cheque Customer', 'due_balance' => 10000]);
    $cheque = $customer->payments()->create([
        'amount' => 10000,
        'payment_method' => 'cheque',
        'date' => '2026-07-01',
        'reference' => 'CHQ-999',
        'cheque_no' => '999111',
        'cheque_bank' => 'Commercial Bank',
        'cheque_date' => '2026-07-15',
        'cheque_status' => 'returned',
    ]);

    $rows = app(CustomerLedgerService::class)->rows($customer);
    $row = $rows->firstWhere('id', $cheque->id);

    expect($row)->not->toBeNull()
        ->and($row['badge'])->toBe('Returned Cheque')
        ->and($row['cheque_status'])->toBe('returned')
        ->and($row['credit'])->toBe(0.0)
        ->and($row['amount'])->toBe(10000.0);

    Livewire::test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Returned Cheque')
        ->assertSee('999111')
        ->assertSee('Commercial Bank');
});

test('recalculateDueBalance and syncCustomerBalance correctly synchronizes customer due', function () {
    $customer = Customer::query()->create(['name' => 'Sync Test Customer', 'opening_balance' => 1000, 'due_balance' => 99999]);
    $customer->sales()->create([
        'invoice_no' => 'INV-SYNC-1',
        'date' => today(),
        'grand_total' => 5000,
        'paid_amount' => 2000,
        'due_amount' => 3000,
        'payment_status' => 'partial',
    ]);

    $customer->payments()->create([
        'amount' => 1500,
        'payment_method' => 'cash',
        'date' => today(),
    ]);

    // Returned cheque on account should NOT reduce due
    $customer->payments()->create([
        'amount' => 2000,
        'payment_method' => 'cheque',
        'cheque_status' => 'returned',
        'date' => today(),
    ]);

    // Expected due = 1000 (opening) + 3000 (sales due) - 1500 (account cash) = 2500
    $recalculated = $customer->recalculateDueBalance();
    expect($recalculated)->toBe(2500.0)
        ->and((float) $customer->fresh()->due_balance)->toBe(2500.0);

    // Livewire action
    $customer->update(['due_balance' => 8888]);
    Livewire::test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->call('syncCustomerBalance', $customer->id)
        ->assertHasNoErrors();

    expect((float) $customer->fresh()->due_balance)->toBe(2500.0);
});

test('deleting bank transfer payment supports various casing and spacing', function () {
    $customer = Customer::query()->create(['name' => 'Case Test Customer', 'due_balance' => 1000]);
    $payment = $customer->payments()->create([
        'amount' => 1000,
        'payment_method' => 'BANK_TRANSFER',
        'date' => today(),
        'reference' => 'PAY-CASE',
    ]);

    app(ChequePaymentService::class)->deleteCustomerPayment($payment, $customer->id);
    expect((float) $customer->fresh()->due_balance)->toBe(2000.0);
    $this->assertModelMissing($payment);
});

test('customer dues can be adjusted to zero via adjustment action', function () {
    $customer = Customer::query()->create([
        'name' => 'Mobile communication',
        'due_balance' => 11000,
        'opening_balance' => 0,
    ]);

    $component = Livewire::test('pages::parties.customers')
        ->call('initiateAdjustment', $customer->id)
        ->assertSet('adjustingCustomerId', $customer->id)
        ->assertSet('adjustType', 'reduce')
        ->assertSet('adjustAmount', 11000.0)
        ->call('setAdjustmentToZero')
        ->assertSet('adjustAmount', 11000.0)
        ->set('adjustReason', 'Duplicate payment correction')
        ->call('saveAdjustment')
        ->assertHasNoErrors();

    expect((float) $customer->fresh()->due_balance)->toBe(0.0);

    // Verify polymorphic payment record
    $adjustmentPayment = $customer->payments()->where('payment_method', 'adjustment')->first();
    expect($adjustmentPayment)->not->toBeNull()
        ->and((float) $adjustmentPayment->amount)->toBe(11000.0)
        ->and($adjustmentPayment->notes)->toBe('Duplicate payment correction');

    // Verify ledger mapping
    $ledgerRows = app(CustomerLedgerService::class)->rows($customer);
    $adjRow = $ledgerRows->firstWhere('id', $adjustmentPayment->id);
    expect($adjRow)->not->toBeNull()
        ->and($adjRow['type'])->toBe('adjustment')
        ->and($adjRow['badge'])->toBe('Adjustment')
        ->and($adjRow['credit'])->toBe(11000.0)
        ->and($adjRow['debit'])->toBe(0.0)
        ->and($adjRow['description'])->toBe('Duplicate payment correction');

    // Verify ledger view contains the Adjustment badge
    Livewire::test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Adjustment')
        ->assertSee('ADJUSTMENT')
        ->assertSee('Duplicate payment correction');

    // Deleting the adjustment should restore due balance back to 11,000
    app(ChequePaymentService::class)->deleteCustomerPayment($adjustmentPayment, $customer->id);
    expect((float) $customer->fresh()->due_balance)->toBe(11000.0);
});

test('customer balance adjustment does not pollute cash flow ledger reports', function () {
    $customer = Customer::query()->create(['name' => 'Non Cash Adjust Customer', 'due_balance' => 5000]);
    $payment = $customer->payments()->create([
        'amount' => 5000,
        'payment_method' => 'adjustment',
        'date' => today()->format('Y-m-d'),
        'reference' => 'ADJ-TEST',
        'notes' => 'Non-cash correction',
    ]);

    $accountingLedger = app(AccountingLedgerService::class);
    $entries = $accountingLedger->transactions(today()->toDateString(), today()->toDateString());
    $hasPaymentInCash = $entries->contains(fn ($e) => ($e['reference'] ?? '') === 'ADJ-TEST');
    expect($hasPaymentInCash)->toBeFalse();
});
