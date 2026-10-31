<?php

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChequePaymentService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

function supplierSummaryFixture(float $due, ?float $deducted, float $pending = 1000): Supplier
{
    $supplier = Supplier::query()->create(['name' => 'Summary Supplier', 'due_balance' => $due]);
    $supplier->payments()->create([
        'amount' => $pending, 'payment_method' => 'cheque', 'date' => today(),
        'cheque_no' => 'SUMMARY-CHEQUE', 'cheque_date' => today()->addDay(), 'cheque_status' => 'pending',
        'supplier_deduction_amount' => $deducted,
    ]);

    return $supplier;
}

test('supplier summaries distinguish already reserved legacy partial and unreserved pending cheques', function (float $due, ?float $deducted) {
    $supplier = supplierSummaryFixture($due, $deducted);
    $summary = Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary();
    expect($summary)->toBe(['total_due' => 3000.0, 'pending' => 1000.0, 'due_after_pending' => 2000.0]);
    expect((float) $supplier->fresh()->due_balance)->toBe($due)
        ->and($supplier->payments()->sole()->supplier_deduction_amount)->toBe($deducted === null ? null : number_format($deducted, 2, '.', ''));
})->with([[2000.0, 1000.0], [3000.0, 0.0], [2400.0, 600.0], [2000.0, null]]);

test('supplier summaries combine purchase holds and account holds without counting customer copies passed cheques returned cheques or cash', function () {
    $supplier = supplierSummaryFixture(2000, 1000);
    $customer = Customer::query()->create(['name' => 'Source Summary Customer']);
    $source = $customer->payments()->create(['amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    $supplier->payments()->sole()->update(['source_payment_id' => $source->id]);
    $purchase = $supplier->purchases()->create(['invoice_no' => 'SUMMARY-PUR', 'date' => today(), 'total_amount' => 500, 'grand_total' => 500, 'paid_amount' => 0, 'due_amount' => 0, 'payment_status' => 'cheque_pending']);
    $purchase->payments()->create(['amount' => 500, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    foreach (['passed', 'returned'] as $status) {
        $supplier->payments()->create(['amount' => 250, 'payment_method' => 'cheque', 'cheque_status' => $status, 'date' => today()]);
    }
    $supplier->payments()->create(['amount' => 100, 'payment_method' => 'cash', 'date' => today()]);
    expect(Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary())
        ->toBe(['total_due' => 3500.0, 'pending' => 1500.0, 'due_after_pending' => 2000.0]);
});

test('supplier totals include every matching page and respond to search while excluding archived suppliers', function () {
    foreach (range(1, 13) as $index) {
        $supplier = supplierSummaryFixture(900, 100, 100);
        $supplier->update(['name' => 'Bulk Summary '.$index]);
    }
    Supplier::query()->create(['name' => 'Other Company', 'company_name' => 'Unique Distributor', 'due_balance' => 500]);
    $archived = Supplier::query()->create(['name' => 'Archived Summary', 'due_balance' => 9999]);
    $archived->delete();
    $page = Livewire::test('pages::parties.suppliers')->assertSee('Total Due')->assertSee('Total Pending')->assertSee('Due After Pending');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 13500.0, 'pending' => 1300.0, 'due_after_pending' => 12200.0]);
    expect($page->instance()->suppliers->total())->toBe(14);
    $page->call('setPage', 2);
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 13500.0, 'pending' => 1300.0, 'due_after_pending' => 12200.0]);
    $page->set('search', 'Unique Distributor');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 500.0, 'pending' => 0.0, 'due_after_pending' => 500.0]);
    $page->set('search', 'No matches');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 0.0, 'pending' => 0.0, 'due_after_pending' => 0.0]);
});

test('excess pending on one supplier never cancels the due owed to another supplier', function () {
    supplierSummaryFixture(1000, 0, 2000);
    Supplier::query()->create(['name' => 'Different Supplier', 'due_balance' => 3000]);
    $page = Livewire::test('pages::parties.suppliers');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 4000.0, 'pending' => 2000.0, 'due_after_pending' => 3000.0]);
});

test('supplier cards and ledger show the same three amounts without changing stored balances', function () {
    $supplier = supplierSummaryFixture(4162876, 0, 3052910);
    $response = $this->get(route('parties.suppliers'))->assertOk()->assertSee('1,109,966.00');
    if ($previewDirectory = getenv('SUPPLIER_PREVIEW_DIR')) {
        if (! is_dir($previewDirectory)) {
            mkdir($previewDirectory, 0700, true);
        }
        file_put_contents($previewDirectory.'/suppliers.html', $response->getContent());
    }
    $page = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->assertSee('4,162,876.00')->assertSee('3,052,910.00')->assertSee('1,109,966.00');
    expect($page->instance()->selectedSupplier->payableSummary())
        ->toBe(['total_due' => 4162876.0, 'pending' => 3052910.0, 'due_after_pending' => 1109966.0]);
    expect((float) $supplier->fresh()->due_balance)->toBe(4162876.0);
});

test('payoff defaults to the due after pending and cannot pay a pending amount twice', function () {
    $supplier = supplierSummaryFixture(3000, 0);
    $page = Livewire::test('pages::parties.suppliers')->call('initiatePayment', $supplier->id)
        ->assertSet('payAmount', 2000.0)->set('payAmount', 2500)->call('savePayment')->assertHasNoErrors();
    expect($supplier->payments()->where('payment_method', 'cash')->count())->toBe(0);
    $page->set('payAmount', 2000)->call('savePayment')->assertHasNoErrors();
    expect($supplier->payments()->where('payment_method', 'cash')->count())->toBe(1)
        ->and($page->instance()->supplierTotals)->toBe(['total_due' => 1000.0, 'pending' => 1000.0, 'due_after_pending' => 0.0]);
});

test('supplier totals follow pending passed returned and deleted cheque payments', function () {
    $supplier = Supplier::query()->create(['name' => 'Lifecycle Summary Supplier', 'due_balance' => 3000]);
    $customer = Customer::query()->create(['name' => 'Lifecycle Summary Customer', 'opening_balance' => 1000, 'due_balance' => 0]);
    $source = $customer->payments()->create(['amount' => 1000, 'payment_method' => 'cheque', 'cheque_status' => 'pending', 'date' => today()]);
    $service = app(ChequePaymentService::class);
    $issued = $service->assignSupplier($source, $supplier->id);
    $page = Livewire::test('pages::parties.suppliers');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 3000.0, 'pending' => 1000.0, 'due_after_pending' => 2000.0]);
    $service->pass($issued);
    $page->call('$refresh');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 2000.0, 'pending' => 0.0, 'due_after_pending' => 2000.0]);
    $service->markReturned($issued);
    $page->call('$refresh');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 3000.0, 'pending' => 0.0, 'due_after_pending' => 3000.0]);
    $service->revertToPending($issued);
    $service->delete($issued);
    $page->call('$refresh');
    expect($page->instance()->supplierTotals)->toBe(['total_due' => 3000.0, 'pending' => 0.0, 'due_after_pending' => 3000.0]);
});
