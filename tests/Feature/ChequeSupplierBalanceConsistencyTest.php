<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChequePaymentService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

function chequeBalanceSupplier(float $due, float $pending, ?float $deducted): Supplier
{
    $supplier = Supplier::query()->create(['name' => 'Balance Supplier', 'due_balance' => $due]);
    $supplier->payments()->create([
        'amount' => $pending, 'payment_method' => 'cheque', 'cheque_status' => 'pending',
        'date' => today(), 'supplier_deduction_amount' => $deducted,
    ]);

    return $supplier;
}

function chequeBalanceReceipt(float $amount, string $status = 'pending'): Payment
{
    $customer = Customer::query()->create(['name' => 'Balance Source Customer']);

    return $customer->payments()->create([
        'amount' => $amount, 'payment_method' => 'cheque', 'cheque_status' => $status,
        'date' => today(), 'cheque_no' => 'BALANCE-SOURCE',
    ]);
}

test('cheque assignment and supplier directory show identical totals for mixed historical reservations', function () {
    $supplier = chequeBalanceSupplier(4248710, 3067910, 2064615);
    $supplier->update(['name' => 'EZ LINK']);
    $payment = $supplier->payments()->sole();
    $before = $payment->fresh()->getAttributes();
    $source = chequeBalanceReceipt(41420);
    $directory = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id);
    $report = Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $source->id)
        ->assertSee('EZ LINK · Due After Pending: Rs 3,245,415.00')
        ->set('assignedSupplierId', (string) $supplier->id)
        ->assertSee('Total Due')->assertSee('Total Pending')->assertSee('Due After Pending')
        ->assertSee('Rs 6,313,325.00')->assertSee('Rs 3,067,910.00')->assertSee('Rs 3,245,415.00');
    expect($report->instance()->assignmentSupplier->payableSummary())->toBe($directory->instance()->selectedSupplier->payableSummary())
        ->and($report->instance()->assignmentSupplier->payableSummary())->toBe([
            'total_due' => 6313325.0, 'pending' => 3067910.0, 'due_after_pending' => 3245415.0,
        ])
        ->and((float) $supplier->fresh()->due_balance)->toBe(4248710.0)
        ->and($payment->fresh()->getAttributes())->toBe($before);
});

test('new cheque assignments reject amounts above the displayed available balance and allow the exact remaining amount', function (float $due, ?float $deducted) {
    $supplier = chequeBalanceSupplier($due, 2000, $deducted);
    $source = chequeBalanceReceipt(1500);
    $before = $supplier->fresh()->getAttributes();
    $page = Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $source->id)
        ->set('assignedSupplierId', (string) $supplier->id)->assertSee('Due After Pending: Rs 1,000.00')
        ->call('saveChequeSupplier')->assertHasErrors('assignedSupplierId')->assertSee('due after pending cheques');
    expect($source->issuedPayments()->count())->toBe(0)
        ->and($supplier->fresh()->getAttributes())->toBe($before);
    $exact = chequeBalanceReceipt(1000);
    $page->call('assignChequeSupplier', $exact->id)->set('assignedSupplierId', (string) $supplier->id)
        ->call('saveChequeSupplier')->assertHasNoErrors()->assertSee('Due After Pending: Rs 0.00');
    expect((float) $supplier->fresh()->due_balance)->toBe($due - 1000)
        ->and($exact->issuedPayments()->count())->toBe(1)
        ->and($page->instance()->suppliers->firstWhere('id', $supplier->id)->payableSummary()['due_after_pending'])->toBe(0.0);
})->with([
    'unreserved pending' => [3000.0, 0.0],
    'partially reserved pending' => [2500.0, 500.0],
    'fully reserved pending' => [1000.0, 2000.0],
    'legacy reserved pending' => [1000.0, null],
]);

test('failed reassignment does not restore the original deduction or change either supplier', function (string $status) {
    $oldSupplier = Supplier::query()->create(['name' => 'Previous Balance Supplier', 'due_balance' => 3000]);
    $target = chequeBalanceSupplier(3000, 2000, 0);
    $source = chequeBalanceReceipt(1500, $status);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $oldSupplier->id);
    expect(fn () => $service->assignSupplier($issued, $target->id))->toThrow(ValidationException::class);
    expect((float) $oldSupplier->fresh()->due_balance)->toBe(1500.0)
        ->and((float) $target->fresh()->due_balance)->toBe(3000.0)
        ->and($issued->fresh()->paymentable_id)->toBe($oldSupplier->id)
        ->and((float) $issued->fresh()->supplier_deduction_amount)->toBe(1500.0);
})->with(['pending', 'passed']);

test('same supplier assignment repairs an existing hold without reserving its amount twice', function (float $due, float $deducted) {
    $supplier = chequeBalanceSupplier($due, 1000, $deducted);
    $payment = $supplier->payments()->sole();
    $page = Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $payment->id)
        ->assertSee('Due After Pending: Rs 0.00')->call('saveChequeSupplier')->assertHasNoErrors();
    expect((float) $supplier->fresh()->due_balance)->toBe(0.0)
        ->and((float) $payment->fresh()->supplier_deduction_amount)->toBe(1000.0)
        ->and($page->instance()->suppliers->firstWhere('id', $supplier->id)->payableSummary()['due_after_pending'])->toBe(0.0);
})->with([[0.0, 1000.0], [1000.0, 0.0], [600.0, 400.0]]);

test('purchase cheque holds are included once in assignment balances and changing the selection updates its summary', function () {
    $supplier = Supplier::query()->create(['name' => 'Purchase Hold Balance', 'due_balance' => 1000]);
    $purchase = $supplier->purchases()->create(['invoice_no' => 'PUR-BALANCE', 'date' => today(), 'grand_total' => 2000, 'due_amount' => 0]);
    $purchase->payments()->create(['amount' => 2000, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    $other = Supplier::query()->create(['name' => 'Different Balance', 'due_balance' => 250]);
    $source = chequeBalanceReceipt(1000);
    $page = Livewire::test('pages::reports.cheques')->call('assignChequeSupplier', $source->id)
        ->set('assignedSupplierId', (string) $supplier->id)->assertSee('Rs 3,000.00')->assertSee('Rs 2,000.00');
    expect($page->instance()->assignmentSupplier->payableSummary())->toBe(['total_due' => 3000.0, 'pending' => 2000.0, 'due_after_pending' => 1000.0]);
    $page->set('assignedSupplierId', (string) $other->id);
    expect($page->instance()->assignmentSupplier->payableSummary()['due_after_pending'])->toBe(250.0);
    $page->set('assignedSupplierId', (string) $supplier->id)->call('saveChequeSupplier')->assertHasNoErrors();
    expect((float) $supplier->fresh()->due_balance)->toBe(0.0);
});

test('returned cheques can be assigned without using available supplier due', function () {
    $supplier = chequeBalanceSupplier(2000, 2000, 0);
    $source = chequeBalanceReceipt(1500, 'returned');
    $assigned = app(ChequePaymentService::class)->assignSupplier($source, $supplier->id);
    expect((float) $supplier->fresh()->due_balance)->toBe(2000.0)
        ->and((float) $assigned->supplier_deduction_amount)->toBe(0.0);
});
