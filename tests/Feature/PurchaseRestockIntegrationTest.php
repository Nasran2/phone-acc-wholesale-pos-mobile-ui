<?php

use App\Models\Product;
use App\Models\Purchase;
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
