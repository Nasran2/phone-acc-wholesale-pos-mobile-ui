<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
});

test('billing shows an existing customer advance as a positive credit amount and updates when the customer changes', function () {
    $creditCustomer = Customer::query()->create(['name' => 'Advance Customer', 'due_balance' => -1500]);
    $dueCustomer = Customer::query()->create(['name' => 'Due Customer', 'due_balance' => 2300]);
    $page = Livewire::test('pages::pos.index')->set('customer_id', $creditCustomer->id)
        ->assertSee('Available Advance Credit')->assertSee('Rs 1,500.00')->assertDontSee('Rs -1,500.00');
    expect($page->instance()->customerAdvanceCredit)->toBe(1500.0);
    $page->set('customer_id', $dueCustomer->id)->assertSee('Previous Due')->assertDontSee('Available Advance Credit');
    expect($page->instance()->customerAdvanceCredit)->toBe(0.0)
        ->and((float) $creditCustomer->fresh()->due_balance)->toBe(-1500.0);
});

test('billing shows excess payments accurately without storing them as advance credit', function () {
    $customer = Customer::query()->create(['name' => 'Excess Customer', 'due_balance' => 0]);
    $product = Product::factory()->create(['selling_price' => 1000, 'cost_price' => 500, 'stock_quantity' => 5, 'is_active' => true]);
    $page = Livewire::test('pages::pos.index')->set('customer_id', $customer->id)->call('addToCart', $product->id)
        ->set('paymentRows', [['method' => 'cash', 'amount' => 1200, 'reference' => '', 'cheque_no' => '', 'cheque_bank' => '', 'cheque_date' => '']])
        ->assertSee('Excess Payment')->assertSee('Rs 200.00')->assertSee('This Bill Due');
    expect($page->instance()->checkoutOverpayment)->toBe(200.0)
        ->and((float) $page->instance()->checkoutDuePreview)->toBe(0.0);
    $page->call('submitCheckout')->assertHasErrors('paymentRows');
    expect(Sale::query()->count())->toBe(0)
        ->and((float) $customer->fresh()->due_balance)->toBe(0.0)
        ->and($customer->payments()->count())->toBe(0);
    $page->set('paymentRows.0.amount', 1000)->assertDontSee('Excess Payment');
    expect($page->instance()->checkoutOverpayment)->toBe(0.0);
});
