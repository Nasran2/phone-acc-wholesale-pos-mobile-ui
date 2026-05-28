<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

test('dashboard displays restock buttons linking to purchases create page', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $this->actingAs($user);

    $product = Product::factory()->create([
        'name' => 'Low Stock Cable',
        'stock_quantity' => 1,
        'minimum_stock' => 5,
    ]);

    $response = $this->get(route('dashboard'));
    $response->assertOk();

    // It should contain a link to purchases.create with the product_id parameter
    $expectedUrl = route('purchases.create', ['product_id' => $product->id]);
    $response->assertSee(e($expectedUrl), false);
});

test('purchases create page auto-populates cart with product_id from query parameters', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $product = Product::factory()->create([
        'name' => 'Restock Product',
        'cost_price' => 12.50,
        'selling_price' => 25.00,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['product_id' => $product->id])
        ->test('pages::purchases.create')
        ->assertSet('cart.0.product_id', $product->id)
        ->assertSet('cart.0.name', 'Restock Product')
        ->assertSet('cart.0.cost_price', 12.50)
        ->assertSet('cart.0.selling_price', 25.00);
});

test('suppliers list page auto-opens ledger drawer when supplier_id is passed', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Bulk Supplier',
        'opening_balance' => 0,
        'due_balance' => 100,
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['supplier_id' => $supplier->id])
        ->test('pages::parties.suppliers')
        ->assertSet('selectedSupplierId', $supplier->id);
});

test('purchases index links to supplier list page and products details', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

    $supplier = Supplier::query()->create([
        'name' => 'Premium Supplier',
        'opening_balance' => 0,
        'due_balance' => 100,
    ]);

    $product = Product::factory()->create([
        'name' => 'Restocked Earphones',
    ]);

    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'invoice_no' => 'PUR-TEST-123',
        'date' => '2026-05-21',
        'total_amount' => 1500,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 1500,
        'paid_amount' => 1500,
        'due_amount' => 0,
        'payment_status' => 'paid',
    ]);

    $purchase->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'cost_price' => 150,
        'selling_price' => 200,
        'subtotal' => 1500,
    ]);

    // Check purchase listing page has supplier links
    $response = $this->actingAs($user)->get(route('purchases.index'));
    $response->assertOk();
    $supplierLink = route('parties.suppliers', ['supplier_id' => $supplier->id]);
    $response->assertSee(e($supplierLink), false);

    // Check Livewire component includes supplier and product links when drawer is open
    Livewire::actingAs($user)
        ->test('pages::purchases.index')
        ->call('viewInvoice', $purchase->id)
        ->assertSee(route('parties.suppliers', ['supplier_id' => $supplier->id]))
        ->assertSee(route('products.show', $product->id));
});

test('purchase can be recorded with a selected party cheque hold', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Party Cheque Supplier',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Party Cheque Customer',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Party Cheque Stock',
        'cost_price' => 100,
        'selling_price' => 150,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-PC-100',
        'date' => today(),
        'subtotal_amount' => 1000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1000,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $customerCheque = $sale->payments()->create([
        'amount' => 1000,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'PC-100',
        'cheque_bank' => 'NDB',
        'cheque_no' => 'PC-100',
        'cheque_date' => today()->addDays(2),
        'cheque_status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::purchases.create')
        ->set('invoice_no', 'PUR-PC-100')
        ->set('supplier_id', $supplier->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 10,
            'cost_price' => 100,
            'selling_price' => 150,
            'subtotal' => 1000,
        ]])
        ->set('paid_amount', 1000)
        ->set('payment_method', 'cheque')
        ->set('cheque_type', 'party')
        ->call('selectPartyCheque', $customerCheque->id)
        ->call('savePurchase')
        ->assertHasNoErrors();

    $purchase = Purchase::query()->where('invoice_no', 'PUR-PC-100')->firstOrFail();
    $supplierPayment = $purchase->payments()->firstOrFail();

    expect($purchase->payment_status)->toBe('cheque_pending')
        ->and((float) $purchase->due_amount)->toBe(0.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0)
        ->and($supplierPayment->cheque_type)->toBe('party')
        ->and($supplierPayment->source_payment_id)->toBe($customerCheque->id)
        ->and($supplierPayment->party_customer_id)->toBe($customer->id);
});

test('supplier payoff can be recorded with a cheque hold', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Payoff Cheque Supplier',
        'opening_balance' => 0,
        'due_balance' => 500,
    ]);

    Livewire::actingAs($user)
        ->test('pages::parties.suppliers')
        ->call('initiatePayment', $supplier->id)
        ->set('payMethod', 'own_cheque')
        ->set('payAmount', 500)
        ->set('payChequeNo', 'SUP-CHQ-100')
        ->set('payChequeBank', 'BOC')
        ->set('payChequeDate', today()->addDays(3)->toDateString())
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->where('paymentable_type', Supplier::class)->firstOrFail();

    expect($payment->payment_method)->toBe('cheque')
        ->and($payment->cheque_status)->toBe('pending')
        ->and($payment->cheque_type)->toBe('own')
        ->and($payment->cheque_no)->toBe('SUP-CHQ-100')
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0);
});

test('supplier payoff can be recorded with a party cheque hold', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Party Payoff Supplier',
        'opening_balance' => 0,
        'due_balance' => 1000,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Party Payoff Customer',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-PAYOFF-100',
        'date' => today(),
        'subtotal_amount' => 600,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 600,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);
    $customerCheque = $sale->payments()->create([
        'amount' => 600,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'PAYOFF-CHQ-100',
        'cheque_bank' => 'NDB',
        'cheque_no' => 'PAYOFF-CHQ-100',
        'cheque_date' => today()->addDays(2),
        'cheque_status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::parties.suppliers')
        ->call('initiatePayment', $supplier->id)
        ->set('payMethod', 'party_cheque')
        ->call('selectPayPartyCheque', $customerCheque->id)
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->where('paymentable_type', Supplier::class)->firstOrFail();

    expect($payment->cheque_type)->toBe('party')
        ->and($payment->source_payment_id)->toBe($customerCheque->id)
        ->and($payment->party_customer_id)->toBe($customer->id)
        ->and($payment->cheque_no)->toBe('PAYOFF-CHQ-100')
        ->and((float) $supplier->refresh()->due_balance)->toBe(400.0);
});

test('supplier ledger shows cheque status badge for cheque payoffs', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Ledger Cheque Supplier',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $supplier->payments()->create([
        'amount' => 120,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'LEDGER-CHQ-100',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'LEDGER-CHQ-100',
        'cheque_date' => today()->addDays(2),
        'cheque_status' => 'pending',
        'cheque_type' => 'own',
    ]);

    Livewire::actingAs($user)
        ->test('pages::parties.suppliers')
        ->call('viewLedger', $supplier->id)
        ->assertSee('Pending');
});

test('purchase create defaults to party cheque type', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

    Livewire::actingAs($user)
        ->test('pages::purchases.create')
        ->assertSet('cheque_type', 'party');
});

test('purchase create auto-fills paid amount for cash and bank transfer', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $product = Product::factory()->create([
        'name' => 'Auto Fill Stock',
        'cost_price' => 100,
        'selling_price' => 150,
    ]);

    Livewire::actingAs($user)
        ->test('pages::purchases.create')
        ->call('selectProduct', $product->id)
        ->assertSet('paid_amount', 100.0)
        ->set('discount', 10)
        ->assertSet('paid_amount', 90.0)
        ->set('payment_method', 'bank_transfer')
        ->call('updateCartRow', 0, 'quantity', 2)
        ->assertSet('paid_amount', 190.0);
});

test('party cheque lower than total leaves supplier due with partial status', function () {
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $supplier = Supplier::query()->create([
        'name' => 'Partial Cheque Supplier',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Partial Cheque Customer',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Partial Cheque Stock',
        'cost_price' => 100,
        'selling_price' => 150,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-PC-200',
        'date' => today(),
        'subtotal_amount' => 600,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 600,
        'paid_amount' => 0,
        'due_amount' => 0,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $customerCheque = $sale->payments()->create([
        'amount' => 600,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'PC-200',
        'cheque_bank' => 'BOC',
        'cheque_no' => 'PC-200',
        'cheque_date' => today()->addDays(2),
        'cheque_status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::purchases.create')
        ->set('invoice_no', 'PUR-PC-200')
        ->set('supplier_id', $supplier->id)
        ->set('cart', [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 10,
            'cost_price' => 100,
            'selling_price' => 150,
            'subtotal' => 1000,
        ]])
        ->set('payment_method', 'cheque')
        ->set('cheque_type', 'party')
        ->call('selectPartyCheque', $customerCheque->id)
        ->call('savePurchase')
        ->assertHasNoErrors();

    $purchase = Purchase::query()->where('invoice_no', 'PUR-PC-200')->firstOrFail();

    expect((float) $purchase->due_amount)->toBe(400.0)
        ->and($purchase->payment_status)->toBe('partial')
        ->and((float) $supplier->refresh()->due_balance)->toBe(400.0);
});
