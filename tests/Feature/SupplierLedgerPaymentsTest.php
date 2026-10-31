<?php

use App\Models\Customer;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('supplier ledger includes purchase and account payments for every method and filters them without changing balances', function () {
    $supplier = Supplier::query()->create(['name' => 'Complete Ledger Supplier', 'due_balance' => 4200]);
    $purchase = $supplier->purchases()->create([
        'invoice_no' => 'PUR-ALL-METHODS', 'date' => today(), 'total_amount' => 10000,
        'grand_total' => 10000, 'paid_amount' => 3000, 'due_amount' => 4200, 'payment_status' => 'partial',
    ]);
    $ids = [];
    foreach (['cash', 'bank_transfer', 'cheque', 'card', 'qr'] as $index => $method) {
        foreach (['purchase' => $purchase, 'account' => $supplier] as $type => $owner) {
            $payment = $owner->payments()->create([
                'amount' => ($index + 1) * 100, 'payment_method' => $method, 'date' => today(),
                'reference' => strtoupper($type.'-'.$method), 'cheque_status' => $method === 'cheque' ? 'pending' : null,
                'supplier_deduction_amount' => $method === 'cheque' && $type === 'account' ? 0 : null,
            ]);
            $ids[$method][] = $payment->id;
        }
    }
    PurchaseReturn::query()->create([
        'supplier_id' => $supplier->id, 'purchase_id' => $purchase->id, 'invoice_no' => 'PRET-LEDGER',
        'date' => today(), 'return_type' => 'adjust_due', 'adjusted_amount' => 100,
    ]);
    $otherSupplier = Supplier::query()->create(['name' => 'Separate Supplier']);
    $otherPurchase = $otherSupplier->purchases()->create(['invoice_no' => 'OTHER-PURCHASE', 'date' => today(), 'grand_total' => 999]);
    $otherPurchase->payments()->create(['amount' => 999, 'payment_method' => 'cash', 'date' => today(), 'reference' => 'OTHER-PAYMENT']);
    $otherSupplier->payments()->create(['amount' => 888, 'payment_method' => 'cash', 'date' => today(), 'reference' => 'OTHER-ACCOUNT']);
    $beforePayments = $purchase->payments()->get()->merge($supplier->payments()->get())->mapWithKeys(fn ($payment): array => [$payment->id => $payment->getAttributes()])->all();
    $beforeSummary = Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary();
    $beforePurchase = $purchase->fresh()->getAttributes();

    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->assertSee('Payment Method')->assertSee('Bank Transfer')->assertSee('PURCHASE-CASH')->assertSee('ACCOUNT-BANK_TRANSFER')
        ->assertDontSee('OTHER-PAYMENT')->assertDontSee('OTHER-ACCOUNT');
    $allRows = collect($page->instance()->selectedSupplierLedger);
    expect($allRows)->toHaveCount(12)
        ->and($allRows->where('type', 'purchase_payment'))->toHaveCount(5)
        ->and($allRows->where('type', 'payment'))->toHaveCount(5)
        ->and($allRows->where('type', 'invoice'))->toHaveCount(1)
        ->and($allRows->where('type', 'return'))->toHaveCount(1);
    foreach ($ids as $method => $paymentIds) {
        $page->set('ledgerPaymentMethod', $method)->assertHasNoErrors();
        $rows = collect($page->instance()->selectedSupplierLedger);
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('id')->sort()->values()->all())->toBe(collect($paymentIds)->sort()->values()->all())
            ->and($rows->pluck('payment_method')->unique()->all())->toBe([$method]);
        if ($method === 'cheque') {
            $page->assertSee('Rs 300.00')->assertSee('Pending');
            expect((float) $rows->where('type', 'payment')->sole()['debit'])->toBe(0.0)
                ->and($rows->pluck('cheque_amount')->all())->toBe([300.0, 300.0]);
        }
    }
    $page->set('ledgerPaymentMethod', 'all');
    expect(collect($page->instance()->selectedSupplierLedger))->toHaveCount(12)
        ->and((float) $supplier->fresh()->due_balance)->toBe(4200.0)
        ->and($purchase->fresh()->getAttributes())->toBe($beforePurchase)
        ->and(Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary())->toBe($beforeSummary)
        ->and($purchase->payments()->get()->merge($supplier->payments()->get())->mapWithKeys(fn ($payment): array => [$payment->id => $payment->getAttributes()])->all())->toBe($beforePayments);
});

test('supplier ledger filters reset when opening another supplier or closing the drawer and show an empty result message', function () {
    $supplier = Supplier::query()->create(['name' => 'Filter Supplier']);
    $other = Supplier::query()->create(['name' => 'Next Filter Supplier']);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->set('ledgerPaymentMethod', 'bank_transfer')->assertSee('No transactions match this payment method.')
        ->call('viewLedger', $other->id)->assertSet('ledgerPaymentMethod', 'all')
        ->set('ledgerPaymentMethod', 'cheque')->call('closeLedger')->assertSet('ledgerPaymentMethod', 'all')
        ->assertSet('selectedSupplierId', null);
});

test('supplier ledger displays each purchase cheque once without copying the original customer receipt', function (string $status) {
    $customer = Customer::query()->create(['name' => 'Source Ledger Customer']);
    $source = $customer->payments()->create(['amount' => 40000, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today()]);
    $supplier = Supplier::query()->create(['name' => 'Party Ledger Supplier']);
    $purchase = $supplier->purchases()->create(['invoice_no' => 'PUR-PARTY-LEDGER', 'date' => today(), 'grand_total' => 40000]);
    $issued = $purchase->payments()->create([
        'amount' => 40000, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today(),
        'cheque_no' => '132747', 'source_payment_id' => $source->id, 'party_customer_id' => $customer->id,
    ]);
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->set('ledgerPaymentMethod', 'cheque')->assertSee('Rs 40,000.00')->assertSee('132747');
    $row = collect($page->instance()->selectedSupplierLedger)->sole();
    expect($row['id'])->toBe($issued->id)->and($row['cheque_amount'])->toBe(40000.0)->and($row['cheque_status'])->toBe($status);
})->with(['pending', 'passed', 'returned']);

test('supplier ledger does not infer cash receipts from paid totals or return credits without payment records', function () {
    $supplier = Supplier::query()->create(['name' => 'Legacy Paid Supplier']);
    $supplier->purchases()->create([
        'invoice_no' => 'LEGACY-PAID', 'date' => today(), 'grand_total' => 1000,
        'paid_amount' => 1000, 'return_credit_amount' => 400, 'due_amount' => 0, 'payment_status' => 'paid',
    ]);
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->assertSee('LEGACY-PAID');
    expect(collect($page->instance()->selectedSupplierLedger)->pluck('type')->all())->toBe(['invoice']);
    $page->set('ledgerPaymentMethod', 'cash')->assertSee('No transactions match this payment method.');
    expect($page->instance()->selectedSupplierLedger)->toBe([]);
});
