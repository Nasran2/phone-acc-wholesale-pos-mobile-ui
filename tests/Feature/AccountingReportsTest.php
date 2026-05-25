<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

function seedAccountingReportTransactions(): void
{
    $customer = Customer::query()->create([
        'name' => 'Ledger Customer',
        'phone' => '0772222222',
        'opening_balance' => 0,
        'due_balance' => 500,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-ACCT-100',
        'date' => today(),
        'subtotal_amount' => 1500,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1500,
        'paid_amount' => 1500,
        'due_amount' => 0,
        'payment_status' => 'paid',
        'profit' => 400,
    ]);

    $sale->payments()->create([
        'amount' => 1500,
        'payment_method' => 'cash',
        'date' => today(),
        'reference' => 'CASH-SALE-100',
        'notes' => 'Retail sale inflow',
    ]);

    $customer->payments()->create([
        'amount' => 500,
        'payment_method' => 'bank_transfer',
        'date' => today(),
        'reference' => 'BANK-DUE-100',
        'notes' => 'Due payment bank deposit',
    ]);

    $supplier = Supplier::query()->create([
        'name' => 'Ledger Supplier',
        'phone' => '0773333333',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'invoice_no' => 'PUR-ACCT-100',
        'date' => today(),
        'total_amount' => 700,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 700,
        'paid_amount' => 700,
        'due_amount' => 0,
        'payment_status' => 'paid',
    ]);

    $purchase->payments()->create([
        'amount' => 700,
        'payment_method' => 'cash',
        'date' => today(),
        'reference' => 'CASH-PUR-100',
        'notes' => 'Purchase cash settlement',
    ]);

    Expense::query()->create([
        'category' => 'Utilities',
        'amount' => 250,
        'date' => today(),
        'payment_method' => 'card',
        'reference' => 'CARD-EXP-100',
        'notes' => 'Utility bill card payment',
    ]);

    Expense::query()->create([
        'category' => 'Bank Charges',
        'amount' => 80,
        'date' => today(),
        'payment_method' => 'bank_transfer',
        'reference' => 'BANK-FEE-100',
        'notes' => 'Bank fee transfer',
    ]);
}

test('authenticated users can visit every accounting report page', function (string $routeName, string $heading) {
    $this->actingAs(User::factory()->create());

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee($heading)
        ->assertSee('Payment Method')
        ->assertSee('Search');
})->with([
    'cash book' => ['accounting.cash-book', 'Shop Cash Book'],
    'daily cash closing' => ['accounting.daily-cash-closing', 'Daily Cash Closing'],
    'daily register closing' => ['accounting.daily-register-closing', 'Daily Register Closing'],
    'cash in' => ['accounting.cash-in', 'Cash In'],
    'cash out' => ['accounting.cash-out', 'Cash Out'],
    'cash balance' => ['accounting.cash-balance', 'Cash Balance'],
    'bank transfers' => ['accounting.bank-transfers', 'Bank Transfers'],
    'payment method report' => ['accounting.payment-method-report', 'Payment Method Report'],
    't accounts' => ['accounting.t-accounts', 'T Accounts'],
]);

test('accounting reports separate inflows outflows bank transfers and grouped ledgers', function () {
    seedAccountingReportTransactions();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::accounting.cash-in')
        ->assertSee('Retail sale inflow')
        ->assertSee('Due payment bank deposit')
        ->assertDontSee('Purchase cash settlement')
        ->assertDontSee('Utility bill card payment');

    Livewire::actingAs($user)
        ->test('pages::accounting.cash-out')
        ->assertSee('Purchase cash settlement')
        ->assertSee('Utility bill card payment')
        ->assertSee('Bank fee transfer')
        ->assertDontSee('Retail sale inflow');

    Livewire::actingAs($user)
        ->test('pages::accounting.bank-transfers')
        ->assertSee('Due payment bank deposit')
        ->assertSee('Bank fee transfer')
        ->assertDontSee('Retail sale inflow');

    Livewire::actingAs($user)
        ->test('pages::accounting.payment-method-report')
        ->assertSee('Bank Transfer')
        ->assertSee('Cash')
        ->assertSee('Card');

    Livewire::actingAs($user)
        ->test('pages::accounting.t-accounts')
        ->assertSee('Sales Revenue')
        ->assertSee('Accounts Receivable')
        ->assertSee('Purchase Payments')
        ->assertSee('Operating Expenses');
});
