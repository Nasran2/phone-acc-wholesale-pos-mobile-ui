<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;

function workflowUser(): User
{
    return User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
}

test('customers can be added with an opening due balance', function () {
    Livewire::actingAs(workflowUser())
        ->test('pages::parties.customers')
        ->set('name', 'Step Customer')
        ->set('phone', '0771234000')
        ->set('email', 'step@example.test')
        ->set('address', '12 Market Road')
        ->set('opening_balance', 750)
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = Customer::query()->where('phone', '0771234000')->firstOrFail();

    expect($customer->name)->toBe('Step Customer')
        ->and((float) $customer->opening_balance)->toBe(750.0)
        ->and((float) $customer->due_balance)->toBe(750.0);
});

test('customers can be edited from the customer list', function () {
    $customer = Customer::query()->create([
        'name' => 'Edit Me Customer',
        'phone' => '0771234999',
        'email' => 'old@example.test',
        'address' => 'Old Road',
        'opening_balance' => 250,
        'due_balance' => 250,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::parties.customers')
        ->call('editCustomer', $customer->id)
        ->assertSet('customerId', $customer->id)
        ->assertSet('name', 'Edit Me Customer')
        ->set('name', 'Edited Customer')
        ->set('phone', '0771234888')
        ->set('email', 'new@example.test')
        ->set('address', 'New Road')
        ->call('saveCustomer')
        ->assertHasNoErrors()
        ->assertSet('customerId', null);

    expect($customer->refresh())
        ->name->toBe('Edited Customer')
        ->phone->toBe('0771234888')
        ->email->toBe('new@example.test')
        ->address->toBe('New Road')
        ->and((float) $customer->opening_balance)->toBe(250.0)
        ->and((float) $customer->due_balance)->toBe(250.0);
});

test('cash sale with full payment records paid invoice payment and stock deduction', function () {
    $customer = Customer::query()->create([
        'name' => 'Full Cash Customer',
        'phone' => '0771234001',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Full Pay Case',
        'cost_price' => 100,
        'selling_price' => 250,
        'stock_quantity' => 8,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->call('updateCartQty', 0, 2)
        ->set('customer_id', $customer->id)
        ->set('payment_method', 'cash')
        ->set('paid_amount', 500)
        ->call('submitCheckout')
        ->assertHasNoErrors()
        ->assertSet('successOpen', true);

    $sale = Sale::query()->with('payments', 'items')->firstOrFail();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->grand_total)->toBe(500.0)
        ->and((float) $sale->paid_amount)->toBe(500.0)
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and($sale->payments)->toHaveCount(1)
        ->and($sale->payments->first()->payment_method)->toBe('cash')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and($product->refresh()->stock_quantity)->toBe(6);
});

test('cash sale with partial payment records customer due balance', function () {
    $customer = Customer::query()->create([
        'name' => 'Due Sale Customer',
        'phone' => '0771234002',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Due Pay Charger',
        'cost_price' => 400,
        'selling_price' => 1000,
        'stock_quantity' => 4,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('payment_method', 'cash')
        ->set('paid_amount', 400)
        ->call('submitCheckout')
        ->assertHasNoErrors();

    $sale = Sale::query()->with('payments')->firstOrFail();

    expect($sale->payment_status)->toBe('partial')
        ->and((float) $sale->grand_total)->toBe(1000.0)
        ->and((float) $sale->paid_amount)->toBe(400.0)
        ->and((float) $sale->due_amount)->toBe(600.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(600.0)
        ->and((float) $sale->payments->first()->amount)->toBe(400.0)
        ->and($product->refresh()->stock_quantity)->toBe(3);
});

test('customer can be added inside checkout and used on the sale', function () {
    $product = Product::factory()->create([
        'name' => 'Quick Customer Cable',
        'cost_price' => 50,
        'selling_price' => 175,
        'stock_quantity' => 3,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::pos.index')
        ->set('customerSearch', 'Checkout Customer')
        ->call('openQuickCustomerModal')
        ->set('quickCustomerName', 'Checkout Customer')
        ->set('quickCustomerPhone', '0771234003')
        ->set('quickCustomerEmail', 'checkout@example.test')
        ->call('saveQuickCustomer')
        ->call('addToCart', $product->id)
        ->set('payment_method', 'cash')
        ->set('paid_amount', 175)
        ->call('submitCheckout')
        ->assertHasNoErrors();

    $customer = Customer::query()->where('phone', '0771234003')->firstOrFail();
    $sale = Sale::query()->firstOrFail();

    expect($sale->customer_id)->toBe($customer->id)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $customer->due_balance)->toBe(0.0);
});

test('pos keeps selected customer visible and resets cart for next checkout', function () {
    $walkIn = Customer::query()->create([
        'name' => 'Walk-in Customer',
        'phone' => '0000000000',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    foreach (range(1, 30) as $index) {
        Customer::query()->create([
            'name' => sprintf('Queue Customer %02d', $index),
            'phone' => '077900'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'opening_balance' => 0,
            'due_balance' => 0,
        ]);
    }

    $customer = Customer::query()->create([
        'name' => 'Selected Mobile Customer',
        'phone' => '0771234099',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);

    $product = Product::factory()->create([
        'name' => 'Mobile Reset Cable',
        'cost_price' => 50,
        'selling_price' => 300,
        'stock_quantity' => 3,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::pos.index')
        ->set('customer_id', $customer->id)
        ->assertSee('Selected Mobile Customer')
        ->call('addToCart', $product->id)
        ->set('payment_method', 'card')
        ->set('paid_amount', 300)
        ->call('submitCheckout')
        ->assertHasNoErrors()
        ->assertSet('successOpen', true)
        ->assertSet('cart', [])
        ->assertSet('customer_id', $walkIn->id);
});

test('pos cart quantity can be typed and adjusted with buttons', function () {
    $product = Product::factory()->create([
        'name' => 'Typable Quantity Charger',
        'cost_price' => 100,
        'selling_price' => 250,
        'stock_quantity' => 5,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->call('updateCartQty', 0, '3')
        ->assertSet('cart.0.quantity', 3)
        ->assertSet('cart.0.subtotal', 750.0)
        ->call('updateCartQty', 0, 4)
        ->assertSet('cart.0.quantity', 4)
        ->assertSet('cart.0.subtotal', 1000.0)
        ->call('updateCartQty', 0, '')
        ->assertSet('cart.0.quantity', 1)
        ->assertSet('cart.0.subtotal', 250.0);
});

test('sales due collection updates invoice customer balance and payment ledger', function () {
    $customer = Customer::query()->create([
        'name' => 'Pay Due Customer',
        'phone' => '0771234004',
        'opening_balance' => 0,
        'due_balance' => 600,
    ]);
    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-DUE-100',
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

    Livewire::actingAs(workflowUser())
        ->test('pages::sales.index')
        ->call('viewInvoice', $sale->id)
        ->call('openPayDueModal')
        ->set('payDueAmount', 600)
        ->set('payDueMethod', 'cash')
        ->set('payDueReference', 'DUE-CASH-100')
        ->set('payDueDate', today()->toDateString())
        ->call('submitPayDue')
        ->assertHasNoErrors()
        ->assertSet('payDueModalOpen', false);

    $sale->refresh();

    expect($sale->payment_status)->toBe('paid')
        ->and((float) $sale->paid_amount)->toBe(1000.0)
        ->and((float) $sale->due_amount)->toBe(0.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and(Payment::query()->where('reference', 'DUE-CASH-100')->exists())->toBeTrue();
});

test('purchase entry restocks product updates pricing and supplier due', function () {
    $supplier = Supplier::query()->create([
        'name' => 'Workflow Supplier',
        'phone' => '0771234005',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Restock Battery',
        'cost_price' => 80,
        'selling_price' => 140,
        'stock_quantity' => 5,
        'is_active' => true,
    ]);

    Livewire::actingAs(workflowUser())
        ->test('pages::purchases.create')
        ->set('invoice_no', 'PUR-WORKFLOW-100')
        ->set('date', today()->toDateString())
        ->set('supplier_id', $supplier->id)
        ->call('selectProduct', $product->id)
        ->call('updateCartRow', 0, 'quantity', 4)
        ->call('updateCartRow', 0, 'cost_price', 90)
        ->call('updateCartRow', 0, 'selling_price', 160)
        ->set('discount', 20)
        ->set('payment_method', 'cash')
        ->set('paid_amount', 200)
        ->set('payment_reference', 'PUR-CASH-100')
        ->call('savePurchase')
        ->assertHasNoErrors()
        ->assertRedirect(route('purchases.index', absolute: false));

    $purchase = Purchase::query()->with('items', 'payments')->where('invoice_no', 'PUR-WORKFLOW-100')->firstOrFail();

    expect((float) $purchase->grand_total)->toBe(340.0)
        ->and((float) $purchase->paid_amount)->toBe(200.0)
        ->and((float) $purchase->due_amount)->toBe(140.0)
        ->and($purchase->payment_status)->toBe('partial')
        ->and($purchase->items)->toHaveCount(1)
        ->and((float) $purchase->payments->first()->amount)->toBe(200.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(140.0)
        ->and($product->refresh()->stock_quantity)->toBe(9)
        ->and((float) $product->cost_price)->toBe(90.0)
        ->and((float) $product->selling_price)->toBe(160.0);
});

test('accounting cash book can export a pdf download', function () {
    Livewire::actingAs(workflowUser())
        ->test('pages::accounting.cash-book')
        ->call('downloadPdf')
        ->assertFileDownloaded('cash-book-report.pdf');
});
