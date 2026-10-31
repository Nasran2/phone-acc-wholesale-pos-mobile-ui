<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\ChequePaymentService;
use App\Services\CustomerLedgerService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('deleting one duplicate account receipt restores the customer balance only once', function (string $method) {
    $customer = Customer::query()->create(['name' => 'Duplicate Receipt Customer', 'due_balance' => 28000]);
    $receipts = collect([1, 2])->map(fn () => $customer->payments()->create([
        'amount' => 11000, 'payment_method' => $method, 'date' => '2026-07-02', 'reference' => 'DUPLICATE',
    ]));
    $page = Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)
        ->assertSee('Delete Payment')->call('deletePayment', $receipts[1]->id)->assertHasNoErrors()
        ->assertSee('39,000.00')->call('deletePayment', $receipts[1]->id)->assertHasNoErrors();
    $this->assertModelMissing($receipts[1]);
    $this->assertModelExists($receipts[0]);
    expect((float) $customer->fresh()->due_balance)->toBe(39000.0)
        ->and($page->instance()->selectedCustomerLedger->where('type', 'account_payment')->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'customer_payment_deleted')->count())->toBe(1)
        ->and(app(AccountingLedgerService::class)->transactions('2026-07-02', '2026-07-02')->sum('debit'))->toBe(11000.0);
})->with(['cash', 'bank_transfer', 'card', 'qr']);

test('deleting an invoice receipt preserves other payments pending holds and return deductions', function (string $method) {
    $customer = Customer::query()->create(['name' => 'Invoice Receipt Customer', 'due_balance' => 500]);
    $sale = $customer->sales()->create([
        'invoice_no' => 'INV-REVERSE', 'date' => today(), 'subtotal_amount' => 2000,
        'grand_total' => 2000, 'paid_amount' => 900, 'due_amount' => 500, 'payment_status' => 'partial',
    ]);
    $selected = $sale->payments()->create(['amount' => 600, 'payment_method' => $method, 'date' => today()]);
    $other = $sale->payments()->create(['amount' => 300, 'payment_method' => 'cash', 'date' => today()]);
    $hold = $sale->payments()->create(['amount' => 400, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    $sale->returns()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'RET-REVERSE', 'date' => today(),
        'return_type' => 'adjust_due', 'total_amount' => 200, 'adjusted_amount' => 200,
    ]);
    Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)->call('deletePayment', $selected->id)->assertHasNoErrors();
    expect((float) $sale->fresh()->paid_amount)->toBe(300.0)
        ->and((float) $sale->fresh()->due_amount)->toBe(1100.0)
        ->and($sale->fresh()->payment_status)->toBe('cheque_pending')
        ->and((float) $customer->fresh()->due_balance)->toBe(1100.0);
    $this->assertModelExists($other);
    $this->assertModelExists($hold);
})->with(['cash', 'bank_transfer', 'card', 'qr']);

test('invoice receipt deletion restores partial or full due while retaining available advance credit', function (float $customerBalance, float $expectedBalance) {
    $customer = Customer::query()->create(['name' => 'Credit Receipt Customer', 'due_balance' => $customerBalance]);
    $sale = $customer->sales()->create([
        'invoice_no' => 'INV-CREDIT-REVERSE', 'date' => today(), 'subtotal_amount' => 1000,
        'grand_total' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'paid',
    ]);
    $payment = $sale->payments()->create(['amount' => 1000, 'payment_method' => 'cash', 'date' => today()]);
    app(ChequePaymentService::class)->deleteCustomerPayment($payment, $customer->id);
    expect((float) $sale->fresh()->paid_amount)->toBe(0.0)
        ->and((float) $sale->fresh()->due_amount)->toBe(1000.0)
        ->and($sale->fresh()->payment_status)->toBe('due')
        ->and((float) $customer->fresh()->due_balance)->toBe($expectedBalance);
})->with([[0.0, 1000.0], [-1500.0, -500.0]]);

test('customer ledger cheque deletion uses cheque reversal and protects forwarded receipts', function (string $status) {
    $customer = Customer::query()->create(['name' => 'Customer Cheque Delete', 'due_balance' => $status === 'returned' ? 1000 : 0]);
    $payment = $customer->payments()->create([
        'amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today(),
    ]);
    Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)->call('deletePayment', $payment->id)->assertHasNoErrors();
    expect((float) $customer->fresh()->due_balance)->toBe(1000.0);
    $this->assertModelMissing($payment);
})->with(['pending', 'passed', 'returned']);

test('forwarded customer cheque must be released from the supplier before deleting the receipt', function () {
    $customer = Customer::query()->create(['name' => 'Forwarded Customer', 'due_balance' => 0]);
    $source = $customer->payments()->create(['amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    $supplier = Supplier::query()->create(['name' => 'Forwarded Supplier', 'due_balance' => 3000]);
    $issued = app(ChequePaymentService::class)->assignSupplier($source, $supplier->id);
    Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)->call('deletePayment', $source->id)
        ->assertHasErrors('cheque')->assertSee('Delete its supplier payment first');
    $this->assertModelExists($source);
    $this->assertModelExists($issued);
    expect((float) $customer->fresh()->due_balance)->toBe(0.0);
});

test('receipt deletion rejects payments from another customer or a supplier', function (string $ownerType) {
    $customer = Customer::query()->create(['name' => 'Selected Customer']);
    $other = Customer::query()->create(['name' => 'Other Customer']);
    $owner = match ($ownerType) {
        'account' => $other,
        'invoice' => $other->sales()->create(['invoice_no' => 'OTHER-INVOICE', 'date' => today(), 'grand_total' => 500]),
        'supplier' => Supplier::query()->create(['name' => 'Other Supplier']),
    };
    $payment = $owner->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)->call('deletePayment', $payment->id)->assertNotFound();
    $this->assertModelExists($payment);
})->with(['account', 'invoice', 'supplier']);

test('cashiers cannot delete customer receipts even by directly calling the action', function () {
    $customer = Customer::query()->create(['name' => 'Restricted Receipt']);
    $payment = $customer->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    Livewire::actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]))
        ->test('pages::parties.customers')->call('viewLedger', $customer->id)->assertDontSee('Delete Payment')
        ->call('deletePayment', $payment->id)->assertForbidden();
    $this->assertModelExists($payment);
});

test('supplier party cheque information rows are not customer receipt deletion targets', function () {
    $customer = Customer::query()->create(['name' => 'Party Cheque Information']);
    $supplier = Supplier::query()->create(['name' => 'Party Cheque Vendor']);
    $purchase = Purchase::query()->create(['supplier_id' => $supplier->id, 'invoice_no' => 'PARTY-INFO', 'date' => today(), 'grand_total' => 500]);
    $payment = $purchase->payments()->create([
        'amount' => 500, 'payment_method' => 'cheque', 'cheque_type' => 'party',
        'party_customer_id' => $customer->id, 'cheque_status' => 'pending', 'date' => today(),
    ]);
    expect(app(CustomerLedgerService::class)->rows($customer)->sole())->not->toHaveKey('payment_id');
    Livewire::test('pages::parties.customers')->call('viewLedger', $customer->id)->assertDontSee('Delete Payment')
        ->call('deletePayment', $payment->id)->assertNotFound();
    $this->assertModelExists($payment);
});

test('deleting duplicate invoice receipts respects the capped paid amount and remaining receipt', function () {
    $customer = Customer::query()->create(['name' => 'Capped Duplicate Customer']);
    $sale = $customer->sales()->create([
        'invoice_no' => 'CAPPED-DUPLICATE', 'date' => today(), 'grand_total' => 1000,
        'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'paid',
    ]);
    $first = $sale->payments()->create(['amount' => 1000, 'payment_method' => 'cash', 'date' => today(), 'reference' => 'SAME']);
    $duplicate = $sale->payments()->create(['amount' => 1000, 'payment_method' => 'cash', 'date' => today(), 'reference' => 'SAME']);
    $service = app(ChequePaymentService::class);
    $service->deleteCustomerPayment($duplicate, $customer->id);
    expect((float) $sale->fresh()->paid_amount)->toBe(1000.0)
        ->and((float) $customer->fresh()->due_balance)->toBe(0.0);
    $service->deleteCustomerPayment($first, $customer->id);
    expect((float) $sale->fresh()->paid_amount)->toBe(0.0)
        ->and((float) $sale->fresh()->due_amount)->toBe(1000.0)
        ->and((float) $customer->fresh()->due_balance)->toBe(1000.0);
});
