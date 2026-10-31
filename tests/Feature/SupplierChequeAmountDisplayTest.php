<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('supplier views show the recorded cheque amount independently of its balance deduction', function (string $status, ?float $deduction) {
    $supplier = Supplier::query()->create(['name' => 'Cheque Amount Supplier', 'due_balance' => 50000]);
    $payment = $supplier->payments()->create([
        'amount' => 40000, 'payment_method' => 'cheque', 'date' => today(),
        'cheque_no' => '132747', 'reference' => '132747', 'cheque_date' => today()->addDay(),
        'cheque_status' => $status, 'supplier_deduction_amount' => $deduction,
    ]);
    $storedPayment = $payment->fresh()->getAttributes();
    $summary = Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary();
    $cashLedger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString())->all();

    $ledger = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->assertSee('Cheque Amount')->assertSee('Rs 40,000.00')->assertDontSee('- Rs 0.00');
    $ledgerRow = collect($ledger->instance()->selectedSupplierLedger)->where('type', 'payment')->sole();
    expect($ledgerRow['cheque_amount'])->toBe(40000.0)
        ->and((float) $ledgerRow['debit'])->toBe($deduction ?? 40000.0);

    $transactions = Livewire::test('pages::parties.supplier-transactions')->call('selectSupplier', $supplier->id)
        ->assertSee('Payment / Cheque Amount');
    $transactionRow = $transactions->instance()->supplierTransactions->where('type', 'account_payment')->sole();
    expect($transactionRow['cheque_amount'])->toBe(40000.0)
        ->and($transactionRow['debit'])->toBe($deduction ?? 40000.0);

    $html = $transactions->html();
    $desktop = explode('<tbody', $html, 2)[1];
    $desktop = explode('</tbody>', $desktop, 2)[0];
    $mobile = explode('lg:hidden', $html, 2)[1];
    $mobile = explode('</section>', $mobile, 2)[0];
    foreach ([$desktop, $mobile] as $markup) {
        expect($markup)->toContain('Cheque Amount', 'Rs 40,000.00');
    }

    expect($transactions->instance()->chequeRows->sole()['amount'])->toBe(40000.0)
        ->and($payment->fresh()->getAttributes())->toBe($storedPayment)
        ->and((float) $supplier->fresh()->due_balance)->toBe(50000.0)
        ->and(Supplier::query()->withPendingChequeHold()->findOrFail($supplier->id)->payableSummary())->toBe($summary)
        ->and(app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString())->all())->toEqual($cashLedger);
})->with([
    'pending unreserved' => ['pending', 0.0],
    'pending partial deduction' => ['pending', 12000.0],
    'pending reserved' => ['pending', 40000.0],
    'pending legacy' => ['pending', null],
    'returned' => ['returned', 0.0],
    'passed' => ['passed', 40000.0],
]);

test('supplier cheque displays use each issued record amount even with duplicate cheque numbers and a larger source cheque', function () {
    $customer = Customer::query()->create(['name' => 'Source Cheque Customer']);
    $source = $customer->payments()->create([
        'amount' => 80000, 'payment_method' => 'cheque', 'date' => today(),
        'cheque_no' => 'DUPLICATE', 'cheque_status' => 'pending',
    ]);
    $supplier = Supplier::query()->create(['name' => 'Issued Cheque Supplier', 'due_balance' => 50000]);
    foreach ([40000, 8000] as $amount) {
        $supplier->payments()->create([
            'amount' => $amount, 'payment_method' => 'cheque', 'date' => today(),
            'cheque_no' => 'DUPLICATE', 'cheque_status' => 'pending',
            'supplier_deduction_amount' => 0, 'source_payment_id' => $source->id,
        ]);
    }
    $supplier->payments()->create(['amount' => 250, 'payment_method' => 'cash', 'date' => today()]);
    $ledger = Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)
        ->assertSee('Rs 40,000.00')->assertSee('Rs 8,000.00')->assertSee('- Rs 250.00');
    expect(collect($ledger->instance()->selectedSupplierLedger)->pluck('cheque_amount')->all())->toBe([40000.0, 8000.0, null]);
    $transactions = Livewire::test('pages::parties.supplier-transactions')->call('selectSupplier', $supplier->id)
        ->assertSee('Rs 40,000.00')->assertSee('Rs 8,000.00')->assertSee('Rs 250.00');
    expect($transactions->instance()->supplierTransactions->where('payment_method', 'cash')->sole()['debit'])->toBe(250.0)
        ->and(Payment::query()->findOrFail($source->id)->amount)->toBe('80000.00');
});

test('supplier transaction invoice cheques also show their recorded amount on desktop and mobile', function () {
    $supplier = Supplier::query()->create(['name' => 'Purchase Cheque Supplier']);
    $purchase = $supplier->purchases()->create([
        'invoice_no' => 'PUR-CHEQUE-AMOUNT', 'date' => today(), 'total_amount' => 40000,
        'grand_total' => 40000, 'paid_amount' => 0, 'due_amount' => 0, 'payment_status' => 'cheque_pending',
    ]);
    $purchase->payments()->create([
        'amount' => 40000, 'payment_method' => 'cheque', 'date' => today(),
        'cheque_no' => '132748', 'cheque_status' => 'pending',
    ]);
    $page = Livewire::test('pages::parties.supplier-transactions')->call('selectSupplier', $supplier->id)
        ->assertSee('Cheque Amount')->assertSee('Rs 40,000.00');
    expect($page->instance()->supplierTransactions->sole()['cheque_amount'])->toBe(40000.0);
});
