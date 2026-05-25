<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

test('customer ledger shows bills and payment paid dates with bill numbers', function () {
    $user = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Ledger Customer',
        'phone' => '0771112222',
        'opening_balance' => 500,
        'due_balance' => 1500,
    ]);
    $sale = Sale::query()->create([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-LEDGER-1',
        'date' => '2026-05-18',
        'subtotal_amount' => 2500,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 2500,
        'paid_amount' => 1000,
        'due_amount' => 1500,
        'payment_status' => 'partial',
        'profit' => 0,
    ]);

    $sale->payments()->create([
        'amount' => 1000,
        'payment_method' => 'cash',
        'date' => '2026-05-19',
        'reference' => 'CASH-77',
        'notes' => 'Customer paid against bill.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Bills & Payment Timeline')
        ->assertSee('Bill No')
        ->assertSee('INV-LEDGER-1')
        ->assertSee('Bill Date')
        ->assertSee('2026-05-18')
        ->assertSee('Paid Date')
        ->assertSee('2026-05-19')
        ->assertSee('Payment received for bill')
        ->assertSee('CASH-77')
        ->assertSee('Opening Balance');
});
