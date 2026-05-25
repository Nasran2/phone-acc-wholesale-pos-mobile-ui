<?php

use App\Http\Controllers\DebugPasskeysController;
use App\Http\Controllers\PublicBillController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('bill/{sale:invoice_no}', PublicBillController::class)->name('public.bill');

Route::livewire('developer', 'pages::developer.login')->name('developer.login');
Route::livewire('developer/dashboard', 'pages::developer.dashboard')
    ->middleware('developer')
    ->name('developer.dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('products/categories', 'pages::products.categories')->name('products.categories');
    Route::livewire('products/brands', 'pages::products.brands')->name('products.brands');
    Route::livewire('products/units', 'pages::products.units')->name('products.units');
    Route::livewire('products', 'pages::products.index')->name('products.index');
    Route::livewire('products/create', 'pages::products.create')->name('products.create');
    Route::livewire('products/{product}/edit', 'pages::products.edit')->name('products.edit');
    Route::livewire('products/{product}', 'pages::products.show')->name('products.show');

    Route::livewire('pos/{sale?}', 'pages::pos.index')->name('pos.index');
    Route::livewire('parties/customers', 'pages::parties.customers')->name('parties.customers');
    Route::livewire('parties/suppliers', 'pages::parties.suppliers')->name('parties.suppliers');
    Route::livewire('expenses', 'pages::expenses.index')->name('expenses.index');
    Route::livewire('expenses/add', 'pages::expenses.create')->name('expenses.create');
    Route::livewire('expenses/categories', 'pages::expenses.categories')->name('expenses.categories');
    Route::livewire('purchases', 'pages::purchases.index')->name('purchases.index');
    Route::livewire('purchases/create', 'pages::purchases.create')->name('purchases.create');
    Route::livewire('sales', 'pages::sales.index')->name('sales.index');
    Route::livewire('accounting', 'pages::accounting.index')->name('accounting.index');
    Route::livewire('accounting/cash-book', 'pages::accounting.cash-book')->name('accounting.cash-book');
    Route::livewire('accounting/daily-cash-closing', 'pages::accounting.daily-cash-closing')->name('accounting.daily-cash-closing');
    Route::livewire('accounting/daily-register-closing', 'pages::accounting.daily-register-closing')->name('accounting.daily-register-closing');
    Route::livewire('accounting/cash-in', 'pages::accounting.cash-in')->name('accounting.cash-in');
    Route::livewire('accounting/cash-out', 'pages::accounting.cash-out')->name('accounting.cash-out');
    Route::livewire('accounting/cash-balance', 'pages::accounting.cash-balance')->name('accounting.cash-balance');
    Route::livewire('accounting/bank-transfers', 'pages::accounting.bank-transfers')->name('accounting.bank-transfers');
    Route::livewire('accounting/payment-method-report', 'pages::accounting.payment-method-report')->name('accounting.payment-method-report');
    Route::livewire('accounting/t-accounts', 'pages::accounting.t-accounts')->name('accounting.t-accounts');
    Route::livewire('reports', 'pages::reports.index')->name('reports.index');
    Route::livewire('reports/sales', 'pages::reports.sales')->name('reports.sales');
    Route::livewire('reports/purchases', 'pages::reports.purchases')->name('reports.purchases');
    Route::livewire('reports/profit-loss', 'pages::reports.profit-loss')->name('reports.profit-loss');
    Route::livewire('reports/stock', 'pages::reports.stock')->name('reports.stock');
    Route::livewire('reports/expenses', 'pages::reports.expenses')->name('reports.expenses');
    Route::livewire('reports/receives', 'pages::reports.receives')->name('reports.receives');
    Route::livewire('reports/debits', 'pages::reports.debits')->name('reports.debits');
    Route::livewire('reports/due-bills', 'pages::reports.due-bills')->name('reports.due-bills');
    Route::livewire('reports/customer-dues', 'pages::reports.customer-dues')->name('reports.customer-dues');
    Route::livewire('settings', 'pages::settings.index')->name('settings.index');
    Route::livewire('settings/business-info', 'pages::settings.business-info')->name('settings.business');
    Route::livewire('settings/general', 'pages::settings.general')->name('settings.general');
    Route::livewire('settings/invoice', 'pages::settings.invoice')->name('settings.invoice');
    Route::livewire('settings/pos-settings', 'pages::settings.pos-settings')->name('settings.pos-settings');
    Route::livewire('settings/sms-gateway', 'pages::settings.sms')->name('settings.sms');
    Route::livewire('settings/online-platforms', 'pages::settings.online-platforms')->name('settings.online-platforms');
});

require __DIR__.'/settings.php';

Route::get('debug-passkeys', DebugPasskeysController::class);
