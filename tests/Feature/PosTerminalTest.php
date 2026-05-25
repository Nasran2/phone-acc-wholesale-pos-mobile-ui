<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SampleProductsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('authorized users can open the pos terminal', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $this->seed(SampleProductsSeeder::class);

    $response = $this->actingAs($user)->get(route('pos.index'));

    $response
        ->assertOk()
        ->assertSee('Checkout register')
        ->assertSee('AirPods Pro Clear Case')
        ->assertSee('Selected cart')
        ->assertSee('Share PDF Bill')
        ->assertDontSee('Laravel Starter Kit');
});

test('pos product search filters the catalog', function () {
    $this->seed(SampleProductsSeeder::class);

    Livewire::test('pos.product-catalog')
        ->set('search', 'AirPods')
        ->assertSee('AirPods Pro Clear Case')
        ->assertDontSee('Magnetic Car Holder');
});

test('adding a product to the cart does not rerender the product catalog', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    $component = Livewire::actingAs($user)->test('pages::pos.index');

    $productSelects = 0;

    DB::listen(function (QueryExecuted $query) use (&$productSelects): void {
        $sql = str_replace('"', '`', $query->sql);

        if (str_contains($sql, 'select') && str_contains($sql, 'from `products`')) {
            $productSelects++;
        }
    });

    $component
        ->call('addToCart', $product->id)
        ->assertSet('cart.0.product_id', $product->id)
        ->assertSet('cart.0.quantity', 1);

    expect($productSelects)->toBe(1);
});

test('cashier can edit cart item quantity price and discount', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->call('openCartItemEditor', 0)
        ->assertSet('cartItemEditorOpen', true)
        ->assertSet('editCartName', 'AirPods Pro Clear Case')
        ->assertSet('editUnitPrice', 1500.00)
        ->assertSee('unit: Number($wire.editUnitPrice) || 0', false)
        ->set('editQuantity', 2)
        ->set('editUnitPrice', 1200)
        ->set('editDiscountType', 'percentage')
        ->set('editDiscountValue', 10)
        ->call('saveCartItemEditor')
        ->assertSet('cartItemEditorOpen', false)
        ->assertSet('cart.0.quantity', 2)
        ->assertSet('cart.0.selling_price', 1200.00)
        ->assertSet('cart.0.discount_type', 'percentage')
        ->assertSet('cart.0.discount_value', 10.00)
        ->assertSet('cart.0.price_type', 'custom')
        ->assertSet('cart.0.subtotal', 2160.00)
        ->assertSet('paid_amount', 2160.00)
        ->assertSee('Rs 2,160.00');
});

test('cashier can add a customer from the pos checkout', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->set('customerSearch', 'Nasran')
        ->call('openQuickCustomerModal')
        ->assertSet('customerCreateOpen', true)
        ->set('quickCustomerName', 'Nasran Mobile')
        ->set('quickCustomerPhone', '0771234567')
        ->set('quickCustomerEmail', 'nasran@example.test')
        ->call('saveQuickCustomer')
        ->assertSet('customerCreateOpen', false)
        ->assertSet('customerSearch', '');

    $customer = Customer::query()->where('phone', '0771234567')->firstOrFail();

    expect($customer->name)->toBe('Nasran Mobile')
        ->and((float) $customer->due_balance)->toBe(0.0);
});

test('cheque checkout creates a pending hold payment', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Cheque Customer',
        'phone' => '0770000001',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('payment_method', 'cheque')
        ->set('paid_amount', 1500)
        ->set('cheque_bank', 'BOC')
        ->set('cheque_no', 'CHQ-100')
        ->set('cheque_date', today()->addDays(3)->toDateString())
        ->call('submitCheckout')
        ->assertHasNoErrors();

    $sale = Sale::query()->firstOrFail();
    $payment = Payment::query()->firstOrFail();

    expect($sale->payment_status)->toBe('cheque_pending')
        ->and((float) $sale->paid_amount)->toBe(0.0)
        ->and((float) $sale->due_amount)->toBe(1500.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(1500.0)
        ->and($payment->payment_method)->toBe('cheque')
        ->and($payment->cheque_status)->toBe('pending')
        ->and($payment->cheque_bank)->toBe('BOC')
        ->and($payment->cheque_no)->toBe('CHQ-100');
});

test('cashier can checkout with fixed and percentage discount types', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Discount Customer',
        'phone' => '0770000002',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    // 1. Fixed Discount
    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('discount_type', 'fixed')
        ->set('discount', 200)
        ->set('paid_amount', 1300)
        ->call('submitCheckout')
        ->assertHasNoErrors();

    $sale1 = Sale::query()->orderBy('id', 'desc')->firstOrFail();
    expect((float) $sale1->discount_amount)->toBe(200.0)
        ->and((float) $sale1->grand_total)->toBe(1300.0);

    // 2. Percentage Discount
    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('discount_type', 'percentage')
        ->set('discount', 10) // 10% of 1500 = 150
        ->set('paid_amount', 1350)
        ->call('submitCheckout')
        ->assertHasNoErrors();

    $sale2 = Sale::query()->orderBy('id', 'desc')->firstOrFail();
    expect((float) $sale2->discount_amount)->toBe(150.0)
        ->and((float) $sale2->grand_total)->toBe(1350.0);
});

test('a4 invoice uses the branded print layout and developer footer', function () {
    config(['app.dev_name' => 'Twinsofte.com']);
    Setting::set('invoice_paper_size', 'A4', 'invoice');
    Setting::set('business_name', 'IMRAN POS', 'business');
    Setting::clearCache();

    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'A4 Customer',
        'phone' => '0770000003',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('paid_amount', 1500)
        ->call('submitCheckout')
        ->assertHasNoErrors()
        ->assertSee('Invoice')
        ->assertSee('Bill to')
        ->assertSee('Quantity')
        ->assertSee('Invoice Number')
        ->assertSee('Powered by Twinsofte.com');
});

test('a4 invoice hides empty customer phone and address fields', function () {
    config(['app.dev_name' => 'Twinsofte.com']);
    Setting::set('invoice_paper_size', 'A4', 'invoice');
    Setting::clearCache();

    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'No Contact Customer',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $customer->id)
        ->set('paid_amount', 1500)
        ->call('submitCheckout')
        ->assertHasNoErrors()
        ->assertSee('No Contact Customer')
        ->assertSee('Invoice Number')
        ->assertDontSee('Phone Number')
        ->assertDontSee('Client Address')
        ->assertDontSee('N/A')
        ->assertDontSee('0000000000');
});

test('cheque checkout fails with walk-in customer', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    // Create or retrieve Walk-in Customer
    $walkIn = Customer::query()->firstOrCreate(
        ['phone' => '0000000000'],
        [
            'name' => 'Walk-in Customer',
            'email' => 'walkin@example.com',
            'opening_balance' => 0,
            'due_balance' => 0,
        ]
    );
    $this->seed(SampleProductsSeeder::class);
    $product = Product::query()->where('sku', 'CASE-AIRPODS-PRO')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::pos.index')
        ->call('addToCart', $product->id)
        ->set('customer_id', $walkIn->id)
        ->set('payment_method', 'cheque')
        ->set('paid_amount', 1500)
        ->set('cheque_bank', 'BOC')
        ->set('cheque_no', 'CHQ-100')
        ->set('cheque_date', today()->addDays(3)->toDateString())
        ->call('submitCheckout')
        ->assertHasErrors(['customer_id']);
});
