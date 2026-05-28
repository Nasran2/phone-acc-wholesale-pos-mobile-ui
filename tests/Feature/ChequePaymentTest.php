<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChequePaymentService;
use Livewire\Livewire;

function createChequeSale(string $chequeDate, float $amount = 1000): array
{
    $customer = Customer::query()->create([
        'name' => 'Cheque Ledger Customer',
        'phone' => '0771111111',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 9999),
        'date' => today(),
        'subtotal_amount' => $amount,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => $amount,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $payment = $sale->payments()->create([
        'amount' => $amount,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'CHQ-200',
        'cheque_bank' => 'Commercial Bank',
        'cheque_no' => 'CHQ-200',
        'cheque_date' => $chequeDate,
        'cheque_status' => 'pending',
    ]);

    return [$customer, $sale, $payment];
}

function createChequePurchase(string $chequeDate, string $chequeType = 'own', ?Payment $sourcePayment = null, float $amount = 1000): array
{
    $supplier = Supplier::query()->create([
        'name' => 'Cheque Supplier',
        'phone' => '0772222222',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'invoice_no' => 'PUR-'.fake()->unique()->numberBetween(1000, 9999),
        'date' => today(),
        'total_amount' => $amount,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => $amount,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
    ]);

    $payment = $purchase->payments()->create([
        'amount' => $amount,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => $sourcePayment?->cheque_no ?? 'OWN-100',
        'cheque_bank' => $sourcePayment?->cheque_bank ?? 'BOC',
        'cheque_no' => $sourcePayment?->cheque_no ?? 'OWN-100',
        'cheque_date' => $sourcePayment?->cheque_date?->toDateString() ?? $chequeDate,
        'cheque_status' => 'pending',
        'cheque_type' => $chequeType,
        'source_payment_id' => $sourcePayment?->id,
        'party_customer_id' => $sourcePayment?->paymentable?->customer_id,
    ]);

    return [$supplier, $purchase, $payment];
}

test('passing a pending cheque settles the sale and customer due', function () {
    [$customer, $sale, $payment] = createChequeSale(today()->toDateString());

    app(ChequePaymentService::class)->pass($payment);

    expect($payment->refresh()->cheque_status)->toBe('passed')
        ->and($payment->cheque_processed_at)->not->toBeNull()
        ->and($sale->refresh()->payment_status)->toBe('paid')
        ->and((float) $sale->paid_amount)->toBe(1000.0)
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('returning a pending cheque marks the invoice due', function () {
    [$customer, $sale, $payment] = createChequeSale(today()->toDateString());

    app(ChequePaymentService::class)->markReturned($payment);

    expect($payment->refresh()->cheque_status)->toBe('returned')
        ->and($sale->refresh()->payment_status)->toBe('due')
        ->and((float) $sale->paid_amount)->toBe(0.0)
        ->and((float) $sale->due_amount)->toBe(1000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0);
});

test('pending cheques older than seven days pass automatically', function () {
    [$customer, $sale, $payment] = createChequeSale(today()->subDays(8)->toDateString());

    $processed = app(ChequePaymentService::class)->autoPassOverduePendingCheques(today());

    expect($processed)->toBe(1)
        ->and($payment->refresh()->cheque_status)->toBe('passed')
        ->and($sale->refresh()->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('pending cheques are excluded from accounting cash inflow until passed', function () {
    [$customer, $sale, $payment] = createChequeSale(today()->toDateString());
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::accounting.index')
        ->assertSee('No accounting transactions logged');

    app(ChequePaymentService::class)->pass($payment);
    $payment->refresh();

    expect(Payment::query()
        ->whereDate('date', '>=', $payment->date->toDateString())
        ->whereDate('date', '<=', $payment->date->toDateString())
        ->whereHasMorph('paymentable', [Customer::class, Sale::class])
        ->where(function ($query) {
            $query->where('payment_method', '!=', 'cheque')
                ->orWhere('cheque_status', 'passed');
        })
        ->count())->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::accounting.index')
        ->set('dateRange', 'custom')
        ->set('customStartDate', $payment->date->toDateString())
        ->set('customEndDate', $payment->date->toDateString())
        ->assertSee('Customer receipt checkout transaction')
        ->assertSee('cheque');

    expect((float) $sale->refresh()->paid_amount)->toBe(1000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('passing an own supplier cheque settles purchase and appears as paid', function () {
    [$supplier, $purchase, $payment] = createChequePurchase(today()->toDateString());

    app(ChequePaymentService::class)->pass($payment);

    expect($payment->refresh()->cheque_status)->toBe('passed')
        ->and($purchase->refresh()->payment_status)->toBe('paid')
        ->and((float) $purchase->paid_amount)->toBe(1000.0)
        ->and((float) $purchase->due_amount)->toBe(0.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0);
});

test('party cheque pass settles both customer sale and supplier purchase', function () {
    [$customer, $sale, $sourcePayment] = createChequeSale(today()->toDateString());
    [$supplier, $purchase, $supplierPayment] = createChequePurchase(today()->toDateString(), 'party', $sourcePayment);

    app(ChequePaymentService::class)->pass($supplierPayment);

    expect($sourcePayment->refresh()->cheque_status)->toBe('passed')
        ->and($sale->refresh()->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and($supplierPayment->refresh()->cheque_status)->toBe('passed')
        ->and($purchase->refresh()->payment_status)->toBe('paid')
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0);
});

test('party cheque return makes customer and supplier balances due', function () {
    [$customer, $sale, $sourcePayment] = createChequeSale(today()->toDateString());
    [$supplier, $purchase, $supplierPayment] = createChequePurchase(today()->toDateString(), 'party', $sourcePayment);

    app(ChequePaymentService::class)->markReturned($supplierPayment);

    expect($sourcePayment->refresh()->cheque_status)->toBe('returned')
        ->and($sale->refresh()->payment_status)->toBe('due')
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0)
        ->and($supplierPayment->refresh()->cheque_status)->toBe('returned')
        ->and($purchase->refresh()->payment_status)->toBe('due')
        ->and((float) $purchase->due_amount)->toBe(1000.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(1000.0);
});

test('own supplier cheques auto pass three days after cheque date', function () {
    [$supplier, $purchase, $payment] = createChequePurchase(today()->subDays(4)->toDateString());

    $processed = app(ChequePaymentService::class)->autoPassOverdueOwnSupplierCheques(today());

    expect($processed)->toBe(1)
        ->and($payment->refresh()->cheque_status)->toBe('passed')
        ->and($purchase->refresh()->payment_status)->toBe('paid')
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0);
});

test('customer cheques issued to suppliers are hidden from dashboard follow-up', function () {
    [$customer, $sale, $sourcePayment] = createChequeSale(today()->addDay()->toDateString());
    [$supplier, $purchase, $supplierPayment] = createChequePurchase(today()->addDay()->toDateString(), 'party', $sourcePayment);

    $cheques = app(ChequePaymentService::class)->actionablePendingCheques(today());

    expect($cheques->pluck('id'))
        ->toContain($supplierPayment->id)
        ->not->toContain($sourcePayment->id);
});

test('supplier payoff cheques appear in dashboard follow-up list', function () {
    $supplier = Supplier::query()->create([
        'name' => 'Supplier Cheque Followup',
        'phone' => '0773333333',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $supplier->payments()->create([
        'amount' => 250,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'SUP-CHQ-200',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'SUP-CHQ-200',
        'cheque_date' => today()->addDays(2)->toDateString(),
        'cheque_status' => 'pending',
        'cheque_type' => 'own',
    ]);

    $cheques = app(ChequePaymentService::class)->actionablePendingCheques(today());

    expect($cheques->pluck('id'))->toContain($payment->id);
});

test('returning supplier payoff cheque restores supplier due balance', function () {
    $supplier = Supplier::query()->create([
        'name' => 'Supplier Cheque Return',
        'phone' => '0774444444',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $supplier->payments()->create([
        'amount' => 400,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'SUP-CHQ-RET',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'SUP-CHQ-RET',
        'cheque_date' => today()->addDays(2)->toDateString(),
        'cheque_status' => 'pending',
        'cheque_type' => 'own',
    ]);

    app(ChequePaymentService::class)->markReturned($payment);

    expect($payment->refresh()->cheque_status)->toBe('returned')
        ->and((float) $supplier->refresh()->due_balance)->toBe(400.0);
});

test('party supplier payoff cheque pass settles customer sale', function () {
    [$customer, $sale, $sourcePayment] = createChequeSale(today()->toDateString());

    $supplier = Supplier::query()->create([
        'name' => 'Supplier Party Pass',
        'phone' => '0775555555',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $supplier->payments()->create([
        'amount' => 1000,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => $sourcePayment->cheque_no,
        'cheque_bank' => $sourcePayment->cheque_bank,
        'cheque_no' => $sourcePayment->cheque_no,
        'cheque_date' => $sourcePayment->cheque_date,
        'cheque_status' => 'pending',
        'cheque_type' => 'party',
        'source_payment_id' => $sourcePayment->id,
        'party_customer_id' => $customer->id,
    ]);

    app(ChequePaymentService::class)->pass($payment);

    expect($sourcePayment->refresh()->cheque_status)->toBe('passed')
        ->and($sale->refresh()->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('party supplier payoff cheque return makes customer due and restores supplier balance', function () {
    [$customer, $sale, $sourcePayment] = createChequeSale(today()->toDateString());

    $supplier = Supplier::query()->create([
        'name' => 'Supplier Party Return',
        'phone' => '0776666666',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $supplier->payments()->create([
        'amount' => 1000,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => $sourcePayment->cheque_no,
        'cheque_bank' => $sourcePayment->cheque_bank,
        'cheque_no' => $sourcePayment->cheque_no,
        'cheque_date' => $sourcePayment->cheque_date,
        'cheque_status' => 'pending',
        'cheque_type' => 'party',
        'source_payment_id' => $sourcePayment->id,
        'party_customer_id' => $customer->id,
    ]);

    app(ChequePaymentService::class)->markReturned($payment);

    expect($sourcePayment->refresh()->cheque_status)->toBe('returned')
        ->and($sale->refresh()->payment_status)->toBe('due')
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(1000.0);
});

test('customer due cheques appear in dashboard follow-up list', function () {
    $customer = Customer::query()->create([
        'name' => 'Customer Due Cheque',
        'phone' => '0778888888',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $customer->payments()->create([
        'amount' => 300,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'CUST-CHQ-100',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'CUST-CHQ-100',
        'cheque_date' => today()->addDays(2)->toDateString(),
        'cheque_status' => 'pending',
        'cheque_type' => 'own',
    ]);

    $cheques = app(ChequePaymentService::class)->actionablePendingCheques(today());

    expect($cheques->pluck('id'))->toContain($payment->id);
});

test('returning customer due cheque restores due balance', function () {
    $customer = Customer::query()->create([
        'name' => 'Customer Due Return',
        'phone' => '0779999999',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $payment = $customer->payments()->create([
        'amount' => 500,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'CUST-CHQ-RET',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'CUST-CHQ-RET',
        'cheque_date' => today()->addDays(2)->toDateString(),
        'cheque_status' => 'pending',
        'cheque_type' => 'own',
    ]);

    app(ChequePaymentService::class)->markReturned($payment);

    expect($payment->refresh()->cheque_status)->toBe('returned')
        ->and((float) $customer->refresh()->due_balance)->toBe(500.0);
});

test('sales due payment can be recorded as a cheque hold', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Sales Due Cheque Customer',
        'phone' => '0771010101',
        'opening_balance' => 0,
        'due_balance' => 600,
    ]);
    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-DUE-CHQ-100',
        'date' => today()->toDateString(),
        'subtotal_amount' => 1000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1000,
        'paid_amount' => 400,
        'due_amount' => 600,
        'payment_status' => 'partial',
        'profit' => 250,
    ]);

    Livewire::actingAs($user)
        ->test('pages::sales.index')
        ->call('viewInvoice', $sale->id)
        ->call('openPayDueModal')
        ->set('payDueAmount', 600)
        ->set('payDueMethod', 'cheque')
        ->set('payDueDate', today()->toDateString())
        ->set('payDueChequeBank', 'BOC')
        ->set('payDueChequeNo', 'DUE-CHQ-100')
        ->set('payDueChequeDate', today()->addDays(2)->toDateString())
        ->call('submitPayDue')
        ->assertHasNoErrors();

    $payment = Payment::query()
        ->where('paymentable_type', Sale::class)
        ->where('paymentable_id', $sale->id)
        ->latest('id')
        ->firstOrFail();

    expect($payment->payment_method)->toBe('cheque')
        ->and($payment->cheque_status)->toBe('pending')
        ->and($payment->cheque_no)->toBe('DUE-CHQ-100')
        ->and($sale->refresh()->payment_status)->toBe('cheque_pending')
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and((float) $sale->paid_amount)->toBe(400.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});
