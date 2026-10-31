<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\ChequePaymentService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('supplier ledger deletes only the selected duplicate account payment and restores its full amount once', function (string $method) {
    $supplier = Supplier::query()->create(['name' => 'Duplicate Supplier Payment', 'due_balance' => 1000]);
    $payments = collect([1, 2])->map(fn () => $supplier->payments()->create([
        'amount' => 500, 'payment_method' => $method, 'date' => today(), 'reference' => 'DUPLICATE',
        'supplier_deduction_amount' => 0,
    ]));
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->set('ledgerPaymentMethod', $method)->assertSee('Delete Payment')
        ->call('deletePayment', $payments[1]->id)->assertHasNoErrors()->assertSee('Rs 1,500.00')
        ->call('deletePayment', $payments[1]->id)->assertHasNoErrors()->assertSet('ledgerPaymentMethod', $method);
    $this->assertModelMissing($payments[1]);
    $this->assertModelExists($payments[0]);
    expect((float) $supplier->fresh()->due_balance)->toBe(1500.0)
        ->and(collect($page->instance()->selectedSupplierLedger)->pluck('id')->all())->toBe([$payments[0]->id])
        ->and($page->instance()->supplierTotals['due_after_pending'])->toBe(1500.0)
        ->and(ActivityLog::query()->where('action', 'supplier_payment_deleted')->count())->toBe(1)
        ->and(app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString())->sum('credit'))->toBe(500.0);
})->with(['cash', 'bank_transfer', 'card', 'qr']);

test('supplier cheque deletion restores exactly its applied deduction and removes its hold', function (string $status, ?float $deducted, float $beforeDue, float $afterDue) {
    $supplier = Supplier::query()->create(['name' => 'Supplier Cheque Reversal', 'due_balance' => $beforeDue]);
    $payment = $supplier->payments()->create([
        'amount' => 500, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today(),
        'cheque_no' => '131281', 'supplier_deduction_amount' => $deducted,
    ]);
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->set('ledgerPaymentMethod', 'cheque')->call('deletePayment', $payment->id)->assertHasNoErrors()
        ->assertSee('No transactions match this payment method.')->call('deletePayment', $payment->id)->assertHasNoErrors();
    $this->assertModelMissing($payment);
    expect((float) $supplier->fresh()->due_balance)->toBe($afterDue)
        ->and($page->instance()->selectedSupplier->payableSummary())->toBe(['total_due' => $afterDue, 'pending' => 0.0, 'due_after_pending' => $afterDue])
        ->and(ActivityLog::query()->where('action', 'cheque_deleted')->count())->toBe(1);
})->with([
    'pending fully deducted' => ['pending', 500.0, 1000.0, 1500.0],
    'pending not deducted' => ['pending', 0.0, 1500.0, 1500.0],
    'pending partially deducted' => ['pending', 200.0, 1300.0, 1500.0],
    'pending legacy' => ['pending', null, 1000.0, 1500.0],
    'passed' => ['passed', 500.0, 1000.0, 1500.0],
    'passed legacy' => ['passed', null, 1000.0, 1500.0],
    'returned' => ['returned', 0.0, 1500.0, 1500.0],
    'returned legacy' => ['returned', null, 1500.0, 1500.0],
]);

test('purchase payment deletion preserves other payments return credit due reductions and pending cheques', function (string $method) {
    $supplier = Supplier::query()->create(['name' => 'Mixed Purchase Reversal', 'due_balance' => 1300]);
    $purchase = $supplier->purchases()->create([
        'invoice_no' => 'PUR-DELETE-MIXED', 'date' => today(), 'total_amount' => 2000, 'grand_total' => 2000,
        'paid_amount' => 1100, 'return_credit_amount' => 200, 'due_amount' => 300, 'payment_status' => 'cheque_pending',
    ]);
    $payment = $purchase->payments()->create(['amount' => 600, 'payment_method' => $method, 'date' => today()]);
    $other = $purchase->payments()->create(['amount' => 300, 'payment_method' => 'cash', 'date' => today()]);
    $hold = $purchase->payments()->create(['amount' => 400, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    PurchaseReturn::query()->create([
        'supplier_id' => $supplier->id, 'purchase_id' => $purchase->id, 'invoice_no' => 'PRET-DELETE-MIXED',
        'date' => today(), 'return_type' => 'adjust_due', 'total_amount' => 200, 'adjusted_amount' => 200,
        'due_allocations' => [$purchase->id => 200],
    ]);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->call('deletePayment', $payment->id)->assertHasNoErrors();
    expect((float) $purchase->fresh()->paid_amount)->toBe(500.0)
        ->and((float) $purchase->fresh()->return_credit_amount)->toBe(200.0)
        ->and((float) $purchase->fresh()->due_amount)->toBe(900.0)
        ->and($purchase->fresh()->payment_status)->toBe('cheque_pending')
        ->and((float) $supplier->fresh()->due_balance)->toBe(1900.0);
    $this->assertModelExists($other);
    $this->assertModelExists($hold);
    $this->assertModelMissing($payment);
})->with(['cash', 'bank_transfer', 'card', 'qr']);

test('supplier ledger can delete pending passed and returned purchase cheques with the correct invoice reversal', function (string $status) {
    $supplier = Supplier::query()->create(['name' => 'Purchase Cheque Delete', 'due_balance' => $status === 'returned' ? 1000 : 0]);
    $purchase = $supplier->purchases()->create([
        'invoice_no' => 'PUR-DELETE-CHEQUE', 'date' => today(), 'grand_total' => 1500,
        'paid_amount' => $status === 'passed' ? 1500 : 500, 'due_amount' => $status === 'returned' ? 1000 : 0,
        'payment_status' => $status === 'passed' ? 'paid' : ($status === 'pending' ? 'cheque_pending' : 'partial'),
    ]);
    $purchase->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    $payment = $purchase->payments()->create(['amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today()]);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->set('ledgerPaymentMethod', 'cheque')
        ->call('deletePayment', $payment->id)->assertHasNoErrors();
    expect((float) $purchase->fresh()->paid_amount)->toBe(500.0)
        ->and((float) $purchase->fresh()->due_amount)->toBe(1000.0)
        ->and($purchase->fresh()->payment_status)->toBe('partial')
        ->and((float) $supplier->fresh()->due_balance)->toBe(1000.0);
})->with(['pending', 'passed', 'returned']);

test('deleting an outward party cheque keeps its original customer receipt intact', function (string $ownerType) {
    $customer = Customer::query()->create(['name' => 'Original Party Customer']);
    $source = $customer->payments()->create(['amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => 'passed', 'date' => today()]);
    $beforeSource = $source->fresh()->getAttributes();
    $supplier = Supplier::query()->create(['name' => 'Party Reversal Supplier', 'due_balance' => 0]);
    $owner = $ownerType === 'account' ? $supplier : $supplier->purchases()->create([
        'invoice_no' => 'PARTY-DELETE', 'date' => today(), 'grand_total' => 1000,
        'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'paid',
    ]);
    $issued = $owner->payments()->create([
        'amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => 'passed', 'date' => today(),
        'source_payment_id' => $source->id, 'cheque_type' => 'party', 'supplier_deduction_amount' => 1000,
    ]);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->call('deletePayment', $issued->id)->assertHasNoErrors();
    expect($source->fresh()->getAttributes())->toBe($beforeSource)
        ->and((float) $supplier->fresh()->due_balance)->toBe(1000.0)
        ->and((float) $customer->fresh()->due_balance)->toBe(0.0);
    $this->assertModelMissing($issued);
})->with(['account', 'purchase']);

test('supplier deletion action rejects another supplier or customer payment', function (string $ownerType) {
    $supplier = Supplier::query()->create(['name' => 'Selected Reversal Supplier']);
    $other = Supplier::query()->create(['name' => 'Different Reversal Supplier']);
    $customer = Customer::query()->create(['name' => 'Unrelated Customer']);
    $owner = match ($ownerType) {
        'account' => $other,
        'purchase' => $other->purchases()->create(['invoice_no' => 'OTHER-REVERSE', 'date' => today(), 'grand_total' => 500]),
        'customer' => $customer,
        'sale' => $customer->sales()->create(['invoice_no' => 'SALE-REVERSE', 'date' => today(), 'grand_total' => 500]),
    };
    $payment = $owner->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->call('deletePayment', $payment->id)->assertNotFound();
    $this->assertModelExists($payment);
})->with(['account', 'purchase', 'customer', 'sale']);

test('supplier payment deletion requires accounting permission and a selected supplier', function () {
    $supplier = Supplier::query()->create(['name' => 'Restricted Supplier Reversal']);
    $payment = $supplier->payments()->create(['amount' => 500, 'payment_method' => 'cash', 'date' => today()]);
    Livewire::test('pages::parties.suppliers')->call('deletePayment', $payment->id)->assertNotFound();
    Livewire::actingAs(User::factory()->create(['role' => 'cashier', 'is_active' => true]))
        ->test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->assertDontSee('Delete Payment')
        ->call('deletePayment', $payment->id)->assertForbidden();
    $this->assertModelExists($payment);
});

test('supplier invoice and return rows do not offer payment deletion', function () {
    $supplier = Supplier::query()->create(['name' => 'No Receipt Supplier']);
    $purchase = $supplier->purchases()->create(['invoice_no' => 'NO-RECEIPT', 'date' => today(), 'grand_total' => 500]);
    PurchaseReturn::query()->create(['supplier_id' => $supplier->id, 'purchase_id' => $purchase->id, 'invoice_no' => 'NO-RECEIPT-RETURN', 'date' => today(), 'return_type' => 'adjust_due']);
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->assertDontSee('Delete Payment');
});

test('purchase receipt deletion preserves an existing supplier credit balance', function () {
    $supplier = Supplier::query()->create(['name' => 'Credit Reversal Supplier', 'due_balance' => -1500]);
    $purchase = $supplier->purchases()->create(['invoice_no' => 'CREDIT-REVERSE', 'date' => today(), 'grand_total' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'payment_status' => 'paid']);
    $payment = $purchase->payments()->create(['amount' => 1000, 'payment_method' => 'cash', 'date' => today()]);
    app(ChequePaymentService::class)->deleteSupplierPayment($payment, $supplier->id);
    expect((float) $supplier->fresh()->due_balance)->toBe(-500.0)
        ->and((float) $purchase->fresh()->due_amount)->toBe(1000.0);
});
