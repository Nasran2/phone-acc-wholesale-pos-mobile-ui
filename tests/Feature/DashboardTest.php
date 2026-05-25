<?php

use App\Livewire\Dashboard\ChequeFollowUp;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertSee('Phone accessory performance cockpit')
        ->assertSee('7 day sales signal')
        ->assertSee('Checkout health')
        ->assertSee('Live receipt stream');
});

test('authenticated users can see critical stock alerts when products are low on stock', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a product with normal stock
    Product::factory()->create([
        'name' => 'Healthy iPhone Charger',
        'stock_quantity' => 20,
        'minimum_stock' => 5,
    ]);

    // Create a product with low stock
    Product::factory()->create([
        'name' => 'Alert USB Cable',
        'stock_quantity' => 2,
        'minimum_stock' => 5,
    ]);

    // Create a product out of stock
    Product::factory()->create([
        'name' => 'Depleted Battery case',
        'stock_quantity' => 0,
        'minimum_stock' => 10,
    ]);

    $response = $this->get(route('dashboard'));
    $response
        ->assertOk()
        ->assertSee('Critical Stock Alerts')
        ->assertSee('Alert USB Cable')
        ->assertSee('Depleted Battery case')
        ->assertDontSee('Healthy iPhone Charger');
});

test('authenticated users can see actionable cheque follow up on the dashboard', function () {
    $user = User::factory()->create();
    $customer = Customer::query()->create([
        'name' => 'Cheque Dashboard Customer',
        'phone' => '0771111111',
        'opening_balance' => 0,
        'due_balance' => 1250,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-DASH-CHQ',
        'date' => today(),
        'subtotal_amount' => 1250,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1250,
        'paid_amount' => 0,
        'due_amount' => 1250,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $sale->payments()->create([
        'amount' => 1250,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'CHQ-DASH',
        'cheque_bank' => 'Commercial Bank',
        'cheque_no' => 'CHQ-DASH',
        'cheque_date' => today()->addDay(),
        'cheque_status' => 'pending',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Cheque follow-up')
        ->assertSee('Cheque Dashboard Customer')
        ->assertSee('CHQ-DASH');
});

test('cheque follow up component can mark a pending cheque as passed', function () {
    $user = User::factory()->create();
    $customer = Customer::query()->create([
        'name' => 'Cheque Action Customer',
        'opening_balance' => 0,
        'due_balance' => 900,
    ]);

    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-ACTION-CHQ',
        'date' => today(),
        'subtotal_amount' => 900,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 900,
        'paid_amount' => 0,
        'due_amount' => 900,
        'payment_status' => 'cheque_pending',
        'profit' => 0,
    ]);

    $payment = $sale->payments()->create([
        'amount' => 900,
        'payment_method' => 'cheque',
        'date' => today(),
        'reference' => 'CHQ-ACTION',
        'cheque_bank' => 'Commercial Bank',
        'cheque_no' => 'CHQ-ACTION',
        'cheque_date' => today()->addDay(),
        'cheque_status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test(ChequeFollowUp::class)
        ->call('passChequePayment', $payment->id)
        ->assertDispatched('cheque-updated');

    expect($payment->refresh()->cheque_status)->toBe('passed')
        ->and($sale->refresh()->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});
