<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChequePaymentService;
use Livewire\Livewire;

function seedBusinessReportTransactions(): void
{
    $reportDate = today()->toDateString();

    $customer = Customer::query()->create([
        'name' => 'Report Customer',
        'phone' => '0774444444',
        'opening_balance' => 0,
        'due_balance' => 300,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-REPORT-100',
        'date' => $reportDate,
        'subtotal_amount' => 1200,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1200,
        'paid_amount' => 900,
        'due_amount' => 300,
        'payment_status' => 'partial',
        'profit' => 350,
    ]);

    $sale->payments()->create([
        'amount' => 900,
        'payment_method' => 'cash',
        'date' => $reportDate,
        'reference' => 'RCV-100',
        'notes' => 'Report sale receipt',
    ]);

    $supplier = Supplier::query()->create([
        'name' => 'Report Supplier',
        'phone' => '0775555555',
        'opening_balance' => 0,
        'due_balance' => 400,
    ]);

    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'invoice_no' => 'PUR-REPORT-100',
        'date' => $reportDate,
        'total_amount' => 800,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 800,
        'paid_amount' => 400,
        'due_amount' => 400,
        'payment_status' => 'partial',
    ]);

    $purchase->payments()->create([
        'amount' => 400,
        'payment_method' => 'bank_transfer',
        'date' => $reportDate,
        'reference' => 'DBT-100',
        'notes' => 'Report purchase debit',
    ]);

    Expense::query()->create([
        'category' => 'Utilities',
        'amount' => 125,
        'date' => $reportDate,
        'payment_method' => 'card',
        'reference' => 'EXP-100',
        'notes' => 'Report utility expense',
    ]);
}

test('authenticated users can visit every separate business report page', function (string $routeName, string $heading) {
    $this->actingAs(User::factory()->create());

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee($heading)
        ->assertSee('All reports')
        ->assertSee('PDF / Print')
        ->assertSee('Prepared By')
        ->assertSee('Authorized Signatory');
})->with([
    'sales' => ['reports.sales', 'Sales Report'],
    'purchases' => ['reports.purchases', 'Purchase Report'],
    'profit loss' => ['reports.profit-loss', 'Profit & Loss'],
    'stock' => ['reports.stock', 'Stock Report'],
    'expenses' => ['reports.expenses', 'Expense Report'],
    'receives' => ['reports.receives', 'Receive Report'],
    'debits' => ['reports.debits', 'Debit Report'],
    'due bills' => ['reports.due-bills', 'Due Bills Report'],
    'customer dues' => ['reports.customer-dues', 'Customer Due Report'],
]);

test('business report filters render the correct report data', function () {
    seedBusinessReportTransactions();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.sales')
        ->assertSee('INV-REPORT-100')
        ->assertSee('Report Customer')
        ->set('reportStatus', 'partial')
        ->assertSee('INV-REPORT-100')
        ->set('search', 'missing-invoice')
        ->assertSee('No sales records found');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.receives')
        ->assertSee('Report sale receipt')
        ->assertSee('Cash')
        ->set('paymentMethod', 'bank_transfer')
        ->assertSee('No received payments found');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.debits')
        ->assertSee('Report purchase debit')
        ->assertSee('Report utility expense')
        ->set('paymentMethod', 'bank_transfer')
        ->assertSee('Report purchase debit')
        ->assertDontSee('Report utility expense');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.due-bills')
        ->assertSee('INV-REPORT-100')
        ->assertSee('PUR-REPORT-100')
        ->set('reportStatus', 'customer_due')
        ->assertSee('INV-REPORT-100')
        ->assertDontSee('PUR-REPORT-100');
});

test('pending cheque holds stay out of due reports until returned', function () {
    $customer = Customer::query()->create([
        'name' => 'Pending Cheque Customer',
        'phone' => '0777777777',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-CHEQUE-HOLD',
        'date' => today()->toDateString(),
        'subtotal_amount' => 1500,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1500,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $payment = $sale->payments()->create([
        'amount' => 1500,
        'payment_method' => 'cheque',
        'date' => today()->toDateString(),
        'reference' => 'CHQ-HOLD',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'CHQ-HOLD',
        'cheque_date' => today()->addDay()->toDateString(),
        'cheque_status' => 'pending',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.due-bills')
        ->assertSee('No due bills found')
        ->assertDontSee('INV-CHEQUE-HOLD');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.customer-dues')
        ->set('reportStatus', 'with_due')
        ->assertSee('No customer due records found')
        ->assertDontSee('Pending Cheque Customer');

    app(ChequePaymentService::class)->markReturned($payment);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.due-bills')
        ->assertSee('INV-CHEQUE-HOLD')
        ->assertSee('Pending Cheque Customer');

    expect((float) $sale->refresh()->due_amount)->toBe(1500.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(1500.0);
});
