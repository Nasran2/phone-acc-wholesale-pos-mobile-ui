<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

test('authenticated users can view the products list', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('products.index'))->assertOk();
});

test('authenticated users can view the product create page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('products.create'))
        ->assertOk()
        ->assertSee('New product')
        ->assertSee('Save product');
});

test('authenticated users can view product details', function () {
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->create([
        'name' => 'Matte Shockproof Case',
        'sku' => 'CASE-001',
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Matte Shockproof Case')
        ->assertSee('CASE-001')
        ->assertSee('Selling price');
});

test('users can create a product', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $unit = Unit::factory()->create();

    $response = Livewire::test('pages::products.create')
        ->set('form.name', 'Matte Shockproof Case')
        ->set('form.sku', 'CASE-001')
        ->set('form.barcode', '1234567890123')
        ->set('form.category_id', $category->id)
        ->set('form.brand_id', $brand->id)
        ->set('form.unit_id', $unit->id)
        ->set('form.cost_price', 120.00)
        ->set('form.selling_price', 250.00)
        ->set('form.wholesale_price', 220.00)
        ->set('form.stock_quantity', 15)
        ->set('form.minimum_stock', 3)
        ->set('form.compatible_models', 'iPhone 14, iPhone 15')
        ->set('form.color', 'Charcoal')
        ->set('form.warranty_enabled', true)
        ->set('form.warranty_period_days', 90)
        ->set('form.image', UploadedFile::fake()->image('case.jpg'))
        ->call('save');

    $response->assertHasNoErrors();

    $product = Product::query()->firstOrFail();

    expect($product->name)->toBe('Matte Shockproof Case');
    expect($product->category_id)->toBe($category->id);

    Storage::disk('public')->assertExists($product->image_path);
});
