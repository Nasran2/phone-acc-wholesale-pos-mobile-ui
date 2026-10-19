<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\ChequePaymentService;
use App\Services\SaleReturnService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function dueReturnUser(): User
{
    return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
}

/** @return array{0: Customer, 1: Product, 2: Sale, 3: Sale} */
function customerDueReturnScenario(): array
{
    $customer = Customer::query()->create(['name' => 'Customer Total Due', 'phone' => '0771234567', 'opening_balance' => 0, 'due_balance' => 45000]);
    $product = Product::factory()->create(['name' => 'Return 4050 Cable', 'selling_price' => 4050, 'stock_quantity' => 5, 'is_active' => true]);
    $source = Sale::query()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'INV-PAID-1038', 'date' => today()->subMonth(),
        'subtotal_amount' => 4050, 'grand_total' => 4050, 'paid_amount' => 4050, 'due_amount' => 0, 'payment_status' => 'paid',
    ]);
    $source->items()->create(['product_id' => $product->id, 'quantity' => 1, 'cost_price' => 1000, 'selling_price' => 4050, 'subtotal' => 4050]);
    $source->payments()->create(['amount' => 4050, 'date' => $source->date, 'payment_method' => 'cash']);
    $targetProduct = Product::factory()->create(['name' => 'Unpaid Invoice Product', 'selling_price' => 60850, 'stock_quantity' => 5, 'is_active' => true]);
    $target = Sale::query()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'INV-DUE-1537', 'date' => today()->subWeek(),
        'subtotal_amount' => 60850, 'grand_total' => 60850, 'paid_amount' => 10850, 'due_amount' => 50000, 'payment_status' => 'partial',
    ]);
    $target->items()->create(['product_id' => $targetProduct->id, 'quantity' => 1, 'cost_price' => 10000, 'selling_price' => 60850, 'subtotal' => 60850]);
    $target->payments()->create(['amount' => 10850, 'date' => $target->date, 'payment_method' => 'cash']);
    $customer->payments()->create(['amount' => 5000, 'date' => today()->subDay(), 'payment_method' => 'cash']);

    return [$customer, $product, $source, $target];
}

test('paid bill returns deduct from customer total due in returns and sales screens', function (string $screen) {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $component = Livewire::actingAs(dueReturnUser())->test($screen);
    if ($screen === 'pages::pos.returns') {
        $component->call('selectSale', $source->id);
    } else {
        $component->set('viewingSaleId', $source->id)->call('initiateReturn');
    }
    $component->assertSee('Deduct from customer total due')->call('updateReturnQty', $product->id, 1)
        ->set('returnType', 'adjust_due')->call('submitReturn')->assertHasNoErrors();
    $return = SaleReturn::query()->firstOrFail();

    expect((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0)
        ->and((float) $source->refresh()->due_amount)->toBe(0.0)
        ->and((float) $return->adjusted_amount)->toBe(4050.0)
        ->and((float) $return->refund_amount)->toBe(0.0)
        ->and($return->payments()->count())->toBe(0)
        ->and($return->due_allocations['sale_ids'])->toBe([$target->id]);
})->with(['pages::pos.returns', 'pages::sales.index']);

test('editing a legacy cash refund converts it into a total customer due deduction', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $return = app(SaleReturnService::class)->process($source, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund');
    $return->update(['return_type' => 'adjust_due']);
    $number = $return->invoice_no;
    Livewire::actingAs(dueReturnUser())->test('pages::pos.returns')->call('editReturn', $return->id)
        ->assertSee('Deduct from customer total due')->set('returnType', 'adjust_due')->call('submitReturn')->assertHasNoErrors();

    expect($return->refresh()->invoice_no)->toBe($number)
        ->and((float) $return->refund_amount)->toBe(0.0)
        ->and($return->payments()->count())->toBe(0)
        ->and((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('global due return edits and deletion restore the exact target invoice', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $source->update(['subtotal_amount' => 8100, 'grand_total' => 8100, 'paid_amount' => 8100]);
    $source->items()->update(['quantity' => 2, 'subtotal' => 8100]);
    $service = app(SaleReturnService::class);
    $return = $service->process($source, [$product->id => ['quantity' => 2, 'condition' => 'restock']], 'adjust_due');
    expect((float) $customer->refresh()->due_balance)->toBe(36900.0);
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'adjust_due');
    expect((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0);
    $service->revert($return);
    expect((float) $customer->refresh()->due_balance)->toBe(45000.0)
        ->and((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and((float) $source->refresh()->due_amount)->toBe(0.0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('total customer due caps the deduction and eligible paid remainder becomes a refund', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $customer->update(['due_balance' => 2000]);
    $service = app(SaleReturnService::class);
    $return = $service->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    expect((float) $return->adjusted_amount)->toBe(2000.0)
        ->and((float) $return->refund_amount)->toBe(2050.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and((float) $target->refresh()->due_amount)->toBe(48000.0);
    $service->revert($return);
    expect((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(2000.0);
});

test('opening account dues can be reduced without rewriting historical opening balance', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $target->update(['due_amount' => 0, 'payment_status' => 'paid']);
    $customer->update(['opening_balance' => 45000]);
    $service = app(SaleReturnService::class);
    $return = $service->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    expect((float) $return->due_allocations['account_amount'])->toBe(4050.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $customer->opening_balance)->toBe(45000.0);
    $service->revert($return);
    expect((float) $customer->refresh()->due_balance)->toBe(45000.0);
});

test('billing returns can deduct customer dues without discounting the new bill twice', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $newProduct = Product::factory()->create(['name' => 'New Sale Product', 'selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->assertSet('cartTotal', 10000.0)
        ->call('submitCheckout')->assertHasNoErrors();
    $return = SaleReturn::query()->firstOrFail();
    $checkout = Sale::query()->findOrFail($return->checkout_sale_id);
    expect((float) $checkout->grand_total)->toBe(10000.0)
        ->and((float) $checkout->paid_amount)->toBe(10000.0)
        ->and($checkout->items()->where('quantity', '<', 0)->count())->toBe(0)
        ->and((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->assertSet('returnCompensation', 'adjust_due')->call('submitCheckout')->assertHasNoErrors();
    expect((float) $customer->refresh()->due_balance)->toBe(40950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout->fresh()])
        ->call('removeReturnCredit', $source->id.'-'.$product->id)->call('submitCheckout')->assertHasNoErrors();
    expect(SaleReturn::query()->count())->toBe(0)
        ->and((float) $customer->refresh()->due_balance)->toBe(45000.0)
        ->and((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('billing returns can settle the current bill due and show the allocation on its public bill', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $target->update(['due_amount' => 0]);
    $customer->update(['due_balance' => 0]);
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    Livewire::actingAs(dueReturnUser())->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->set('paid_amount', 0)
        ->call('submitCheckout')->assertHasNoErrors();
    $return = SaleReturn::query()->firstOrFail();
    $checkout = Sale::query()->findOrFail($return->checkout_sale_id);
    expect((float) $checkout->grand_total)->toBe(10000.0)
        ->and((float) $checkout->due_amount)->toBe(5950.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(5950.0);
    $this->get(route('public.bill', $checkout->invoice_no))->assertOk()
        ->assertSee($return->invoice_no)->assertSee('Customer return credit applied to this bill');
    Livewire::actingAs(dueReturnUser())->test('pages::pos.index', ['sale' => $checkout])
        ->assertSet('returnCustomerDuePreview', 5950.0)->call('submitCheckout')->assertHasNoErrors();
    expect((float) $customer->refresh()->due_balance)->toBe(5950.0)
        ->and((float) $checkout->refresh()->due_amount)->toBe(5950.0);
});

test('cheque clearance preserves global return deductions on the credited bill', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $target->update(['due_amount' => 40000]);
    $customer->update(['due_balance' => 35000]);
    $cheque = $target->payments()->create(['amount' => 10000, 'payment_method' => 'cheque', 'date' => today(), 'cheque_date' => today()->addMonth(), 'cheque_status' => 'pending']);
    app(SaleReturnService::class)->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    app(ChequePaymentService::class)->pass($cheque);
    expect((float) $target->refresh()->due_amount)->toBe(35950.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(30950.0);
});

test('credited invoice edits keep allocated credits and deletion requires their reversal', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $return = app(SaleReturnService::class)->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $target->fresh()])->call('submitCheckout')->assertHasNoErrors();
    expect((float) $target->refresh()->due_amount)->toBe(45950.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(40950.0);
    Livewire::actingAs($user)->test('pages::sales.index')->set('viewingSaleId', $target->id)
        ->call('deleteSale')->assertHasErrors('returnItems');
    Livewire::actingAs($user)->test('pages::sales.index')->set('viewingSaleId', $source->id)
        ->call('deleteSale')->assertHasNoErrors();
    expect($return->fresh())->toBeNull()
        ->and((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(45000.0);
});

test('bill credit can be changed to customer due deduction while editing the checkout', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->call('submitCheckout')->assertHasNoErrors();
    $credit = SaleReturn::query()->firstOrFail();
    $checkout = Sale::query()->findOrFail($credit->checkout_sale_id);
    expect((float) $checkout->grand_total)->toBe(5950.0);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->set('returnCompensation', 'adjust_due')->assertSet('cartTotal', 10000.0)
        ->call('submitCheckout')->assertHasNoErrors();

    expect((float) $checkout->refresh()->grand_total)->toBe(10000.0)
        ->and((float) $checkout->paid_amount)->toBe(5950.0)
        ->and((float) $checkout->due_amount)->toBe(4050.0)
        ->and((float) $target->refresh()->due_amount)->toBe(45950.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(45000.0)
        ->and(SaleReturn::query()->firstOrFail()->return_type)->toBe('adjust_due');
});

test('customer due deduction spans unpaid invoices and records the exact amounts to reverse', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $target->update(['due_amount' => 2000]);
    $other = Sale::query()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'INV-OTHER-DUE', 'date' => today(),
        'subtotal_amount' => 10000, 'grand_total' => 10000, 'paid_amount' => 0, 'due_amount' => 10000, 'payment_status' => 'due',
    ]);
    $service = app(SaleReturnService::class);
    $return = $service->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    expect((float) $target->refresh()->due_amount)->toBe(0.0)
        ->and((float) $other->refresh()->due_amount)->toBe(7950.0)
        ->and($return->due_allocations['sale_ids'])->toBe([$target->id, $other->id]);
    $service->revert($return);
    expect((float) $target->refresh()->due_amount)->toBe(2000.0)
        ->and((float) $other->refresh()->due_amount)->toBe(10000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(45000.0);
});

test('unpaid returns cannot turn excess beyond customer dues into unearned cash', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $source->update(['paid_amount' => 0, 'due_amount' => 4050, 'payment_status' => 'due']);
    $customer->update(['due_balance' => 2000]);
    expect(fn () => app(SaleReturnService::class)->process($source, [$product->id => ['quantity' => 1]], 'adjust_due'))
        ->toThrow(ValidationException::class);
    expect((float) $customer->refresh()->due_balance)->toBe(2000.0)
        ->and((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and(SaleReturn::query()->count())->toBe(0);
});

test('cash refunds recognise incoming customer return credits as settled invoice value', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $customer->update(['due_balance' => 4050]);
    $target->update(['grand_total' => 4050, 'subtotal_amount' => 4050, 'paid_amount' => 0, 'due_amount' => 4050]);
    $target->items()->update(['selling_price' => 4050, 'subtotal' => 4050]);
    $target->payments()->delete();
    $service = app(SaleReturnService::class);
    $service->process($source, [$product->id => ['quantity' => 1]], 'adjust_due');
    $targetProduct = $target->items()->firstOrFail()->product_id;
    $return = $service->process($target->fresh(), [$targetProduct => ['quantity' => 1]], 'cash_refund');

    expect((float) $return->refund_amount)->toBe(4050.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('checkout due return edits use net stock changes after returned goods have been sold', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $source->update(['grand_total' => 8100, 'subtotal_amount' => 8100, 'paid_amount' => 8100]);
    $source->items()->update(['quantity' => 2, 'subtotal' => 8100]);
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->call('submitCheckout')->assertHasNoErrors();
    $return = SaleReturn::query()->firstOrFail();
    $checkout = Sale::query()->findOrFail($return->checkout_sale_id);
    $product->update(['stock_quantity' => 0]);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->call('submitCheckout')->assertHasNoErrors();
    expect($product->refresh()->stock_quantity)->toBe(0)
        ->and(SaleReturn::query()->firstOrFail()->id)->toBe($return->id);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout->fresh()])
        ->set('returnCredits.'.$source->id.'-'.$product->id.'.quantity', 2)
        ->call('submitCheckout')->assertHasNoErrors();
    expect($product->refresh()->stock_quantity)->toBe(1)
        ->and((float) $customer->refresh()->due_balance)->toBe(36900.0)
        ->and((float) $target->refresh()->due_amount)->toBe(41900.0);
});

test('editing a credited bill to pay its remaining balance marks the bill paid', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $customer->update(['due_balance' => 0]);
    $target->update(['due_amount' => 0]);
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->set('paid_amount', 0)->call('submitCheckout')->assertHasNoErrors();
    $checkout = Sale::query()->findOrFail(SaleReturn::query()->firstOrFail()->checkout_sale_id);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->set('paid_amount', 5950)->call('submitCheckout')->assertHasNoErrors();
    expect((float) $checkout->refresh()->due_amount)->toBe(0.0)
        ->and($checkout->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('a checkout and its self allocated return can be reduced together', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $customer->update(['due_balance' => 0]);
    $target->update(['due_amount' => 0]);
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->set('paid_amount', 0)->call('submitCheckout')->assertHasNoErrors();
    $return = SaleReturn::query()->firstOrFail();
    $checkout = Sale::query()->findOrFail($return->checkout_sale_id);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->set('cart.0.selling_price', 3000)->set('cart.0.subtotal', 3000)
        ->call('updateReturnCreditPrice', $source->id.'-'.$product->id, 2000)
        ->assertSet('returnCustomerDuePreview', 1000.0)->call('submitCheckout')->assertHasNoErrors();
    expect((float) $checkout->refresh()->grand_total)->toBe(3000.0)
        ->and((float) $checkout->due_amount)->toBe(1000.0)
        ->and((float) $return->refresh()->adjusted_amount)->toBe(2000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(1000.0);
});

test('changing due deduction to bill credit uses net returned stock even after goods are sold', function () {
    [$customer, $product, $source, $target] = customerDueReturnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->call('submitCheckout')->assertHasNoErrors();
    $checkout = Sale::query()->findOrFail(SaleReturn::query()->firstOrFail()->checkout_sale_id);
    $product->update(['stock_quantity' => 0]);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->set('returnCompensation', 'bill_credit')->set('paid_amount', 5950)
        ->call('submitCheckout')->assertHasNoErrors();
    expect($product->refresh()->stock_quantity)->toBe(0)
        ->and((float) $checkout->refresh()->grand_total)->toBe(5950.0)
        ->and((float) $target->refresh()->due_amount)->toBe(50000.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(45000.0)
        ->and(SaleReturn::query()->firstOrFail()->return_type)->toBe('bill_credit');
});

test('a checkout with linked customer due returns cannot move to a different customer', function () {
    [$customer, $product, $source] = customerDueReturnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 10000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = dueReturnUser();
    Livewire::actingAs($user)->test('pages::pos.index')->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)->call('addReturnCredit', $source->items()->firstOrFail()->id)
        ->set('returnCompensation', 'adjust_due')->call('submitCheckout')->assertHasNoErrors();
    $checkout = Sale::query()->findOrFail(SaleReturn::query()->firstOrFail()->checkout_sale_id);
    $other = Customer::query()->create(['name' => 'Other customer', 'phone' => '0777654321', 'due_balance' => 0]);
    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->set('customer_id', $other->id)->call('submitCheckout')->assertHasErrors('customer_id');
    expect($checkout->refresh()->customer_id)->toBe($customer->id);
});
