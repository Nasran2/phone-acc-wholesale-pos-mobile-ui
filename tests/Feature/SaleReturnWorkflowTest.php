<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\FaultyItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\SaleReturnService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function returnWorkflowUser(): User
{
    return User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);
}

function saleForReturn(Customer $customer, Product $product, array $overrides = []): Sale
{
    $sale = Sale::query()->create(array_merge([
        'customer_id' => $customer->id,
        'invoice_no' => 'INV-RET-'.fake()->unique()->numberBetween(1000, 9999),
        'date' => today()->toDateString(),
        'subtotal_amount' => 500,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 500,
        'paid_amount' => 500,
        'due_amount' => 0,
        'payment_status' => 'paid',
        'profit' => 300,
    ], $overrides));

    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'cost_price' => 200,
        'selling_price' => 500,
        'subtotal' => 500,
    ]);

    return $sale;
}

test('same product exchange records replacement cost as expense', function () {
    $customer = Customer::query()->create([
        'name' => 'Exchange Customer',
        'phone' => '0771111111',
        'opening_balance' => 0,
        'due_balance' => 0,
    ]);
    $product = Product::factory()->create([
        'name' => 'Exchange Screen Protector',
        'cost_price' => 200,
        'selling_price' => 500,
        'stock_quantity' => 5,
        'is_active' => true,
    ]);
    $sale = saleForReturn($customer, $product);

    Livewire::actingAs(returnWorkflowUser())
        ->test('pages::pos.returns')
        ->call('selectSale', $sale->id)
        ->call('updateReturnQty', $product->id, 1)
        ->set('returnType', 'exchange')
        ->set('returnNotes', 'Same product replacement')
        ->call('submitReturn')
        ->assertHasNoErrors();

    $expense = Expense::query()->firstOrFail();

    expect($product->refresh()->stock_quantity)->toBe(4)
        ->and((float) $expense->amount)->toBe(200.0)
        ->and($expense->category)->toBe('Product Return Replacement Cost')
        ->and($sale->returns()->count())->toBe(1)
        ->and((float) $sale->refresh()->due_amount)->toBe(0.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);
});

test('return without replacement reduces invoice and customer due', function () {
    $customer = Customer::query()->create([
        'name' => 'Due Return Customer',
        'phone' => '0772222222',
        'opening_balance' => 0,
        'due_balance' => 500,
    ]);
    $product = Product::factory()->create([
        'name' => 'Due Return Charger',
        'cost_price' => 200,
        'selling_price' => 500,
        'stock_quantity' => 3,
        'is_active' => true,
    ]);
    $sale = saleForReturn($customer, $product, [
        'paid_amount' => 0,
        'due_amount' => 500,
        'payment_status' => 'due',
    ]);

    Livewire::actingAs(returnWorkflowUser())
        ->test('pages::pos.returns')
        ->call('selectSale', $sale->id)
        ->call('updateReturnQty', $product->id, 1)
        ->set('returnType', 'adjust_due')
        ->call('submitReturn')
        ->assertHasNoErrors();

    expect((float) $sale->refresh()->due_amount)->toBe(0.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and($product->refresh()->stock_quantity)->toBe(4)
        ->and(Expense::query()->count())->toBe(0);
});

test('pos returns page and sidebar link are available on mobile layout', function () {
    $user = returnWorkflowUser();

    $this->actingAs($user)
        ->get(route('pos.returns'))
        ->assertOk()
        ->assertSee('POS Returns')
        ->assertSee('Search invoice, customer, or phone');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('pos.returns'), false)
        ->assertSee('Returns');
});

/** @return array{0: Customer, 1: Product, 2: Sale} */
function returnScenario(array $saleOverrides = []): array
{
    $customer = Customer::query()->create([
        'name' => 'Return History Customer',
        'phone' => '0775566778',
        'opening_balance' => 0,
        'due_balance' => $saleOverrides['due_amount'] ?? 0,
    ]);
    $product = Product::factory()->create(['name' => 'History Return Cable', 'stock_quantity' => 5]);

    return [$customer, $product, saleForReturn($customer, $product, $saleOverrides)];
}

test('partial paid return reduces due and refunds only the paid remainder and reverts cleanly', function () {
    [$customer, $product, $sale] = returnScenario(['paid_amount' => 200, 'due_amount' => 300, 'payment_status' => 'partial']);
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'adjust_due', 'Partial bill returned');

    expect((float) $return->adjusted_amount)->toBe(300.0)
        ->and((float) $return->refund_amount)->toBe(200.0)
        ->and((float) $sale->refresh()->due_amount)->toBe(0.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0)
        ->and($product->refresh()->stock_quantity)->toBe(6)
        ->and($return->payments()->count())->toBe(1);

    $service->revert($return);

    expect((float) $sale->refresh()->due_amount)->toBe(300.0)
        ->and($sale->payment_status)->toBe('partial')
        ->and((float) $customer->refresh()->due_balance)->toBe(300.0)
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and(Payment::query()->count())->toBe(0)
        ->and(SaleReturn::query()->count())->toBe(0);
});

test('unpaid items cannot be refunded in cash', function () {
    [$customer, $product, $sale] = returnScenario(['paid_amount' => 0, 'due_amount' => 500, 'payment_status' => 'due']);

    expect(fn () => app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1]], 'cash_refund'))
        ->toThrow(ValidationException::class);

    expect(SaleReturn::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and((float) $customer->refresh()->due_balance)->toBe(500.0);
});

test('paid invoice cannot reduce unrelated customer dues', function () {
    [$customer, $product, $sale] = returnScenario();
    $customer->update(['due_balance' => 1000]);

    expect(fn () => app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1]], 'adjust_due'))
        ->toThrow(ValidationException::class);

    expect((float) $customer->refresh()->due_balance)->toBe(1000.0)
        ->and(SaleReturn::query()->count())->toBe(0);
});

test('cash refunds use persisted discounted invoice prices and appear in cash outflows', function () {
    [$customer, $product, $sale] = returnScenario(['discount_amount' => 100, 'grand_total' => 400, 'paid_amount' => 400]);
    $return = app(SaleReturnService::class)->process($sale, [
        $product->id => ['quantity' => 1, 'refund_price' => 99999, 'subtotal' => 99999, 'condition' => 'restock'],
    ], 'cash_refund');

    expect((float) $return->refund_amount)->toBe(400.0)
        ->and((float) $return->items->first()->refund_price)->toBe(400.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(0.0);

    $transactions = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect((float) $transactions->where('account', 'Sales Returns')->sum('credit'))->toBe(400.0);
});

test('return validation rejects invalid quantities conditions and products without changes', function (string $invalid) {
    [$customer, $product, $sale] = returnScenario();
    $items = match ($invalid) {
        'negative' => [$product->id => ['quantity' => -1]],
        'fractional' => [$product->id => ['quantity' => 0.5]],
        'too many' => [$product->id => ['quantity' => 2]],
        'unknown product' => [999999 => ['quantity' => 1]],
        'aliased product' => ['0'.$product->id => ['quantity' => 1], $product->id => ['quantity' => 1]],
        'invalid condition' => [$product->id => ['quantity' => 1, 'condition' => 'anything']],
        default => [$product->id => ['quantity' => 0]],
    };

    expect(fn () => app(SaleReturnService::class)->process($sale, $items, 'cash_refund'))
        ->toThrow(ValidationException::class);

    expect(SaleReturn::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
})->with(['negative', 'fractional', 'too many', 'unknown product', 'aliased product', 'invalid condition', 'empty']);

test('stale invoices cannot be returned twice', function () {
    [$customer, $product, $sale] = returnScenario();
    $sale->load(['items.product', 'returns.items']);
    $service = app(SaleReturnService::class);
    $items = [$product->id => ['quantity' => 1, 'condition' => 'restock']];
    $service->process($sale, $items, 'cash_refund');

    expect(fn () => $service->process($sale, $items, 'cash_refund'))->toThrow(ValidationException::class);
    expect(SaleReturn::query()->count())->toBe(1)
        ->and($product->refresh()->stock_quantity)->toBe(6)
        ->and((float) Payment::query()->sum('amount'))->toBe(500.0);
});

test('faulty and restock returns reverse their exact stock changes', function (string $type, string $condition, int $expectedStock) {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => $condition]], $type);

    expect($product->refresh()->stock_quantity)->toBe($expectedStock)
        ->and(FaultyItem::query()->count())->toBe($condition === 'faulty' ? 1 : 0);

    $service->revert($return);

    expect($product->refresh()->stock_quantity)->toBe(5)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
})->with([
    ['cash_refund', 'faulty', 5],
    ['cash_refund', 'restock', 6],
    ['exchange', 'faulty', 4],
    ['exchange', 'restock', 5],
]);

test('insufficient replacement stock rolls back the entire exchange', function () {
    [$customer, $product, $sale] = returnScenario();
    $product->update(['stock_quantity' => 0]);

    expect(fn () => app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange'))
        ->toThrow(ValidationException::class);

    expect(SaleReturn::query()->count())->toBe(0)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(0);
});

test('multiple partial returns refund a discounted invoice to the last cent', function () {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 300, 'discount_amount' => 200, 'grand_total' => 100, 'paid_amount' => 100]);
    $sale->items()->update(['quantity' => 3, 'selling_price' => 100, 'subtotal' => 300]);
    $service = app(SaleReturnService::class);

    foreach (range(1, 3) as $iteration) {
        $service->process($sale, [$product->id => ['quantity' => 1]], 'cash_refund');
    }

    expect((float) $sale->returns()->sum('refund_amount'))->toBe(100.0)
        ->and($product->refresh()->stock_quantity)->toBe(8);
});

test('duplicate product lines provide the combined remaining return quantity', function () {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 1000, 'grand_total' => 1000, 'paid_amount' => 1000]);
    $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'cost_price' => 200, 'selling_price' => 500, 'subtotal' => 500]);

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('selectSale', $sale->id)
        ->assertSet('returnItems.'.$product->id.'.max', 2)
        ->call('updateReturnQty', $product->id, 2)
        ->set('returnType', 'cash_refund')
        ->call('submitReturn')
        ->assertHasNoErrors()
        ->assertSet('returnDetailOpen', true)
        ->assertSee('Return Details');

    expect((float) $sale->returns()->sum('refund_amount'))->toBe(1000.0);
});

test('completed return history searches original invoices customers and older paginated returns', function () {
    [$customer, $product, $sale] = returnScenario();
    foreach (range(1, 12) as $index) {
        $return = $sale->returns()->create([
            'customer_id' => $customer->id, 'invoice_no' => 'RET-HISTORY-'.$index,
            'date' => today(), 'return_type' => 'exchange', 'refund_amount' => 0, 'adjusted_amount' => 0,
        ]);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'refund_price' => 500, 'subtotal' => 500]);
    }

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->assertSee('Completed Returns')
        ->assertSee('RET-HISTORY-12')
        ->assertDontSee('RET-HISTORY-1<', false)
        ->call('setPage', 2, 'returnsPage')
        ->assertSee('RET-HISTORY-1')
        ->set('historySearch', 'RET-HISTORY-12')
        ->assertSee('RET-HISTORY-12')
        ->assertDontSee('RET-HISTORY-11')
        ->set('historySearch', $sale->invoice_no)
        ->assertSee('RET-HISTORY-12')
        ->set('historySearch', $customer->phone)
        ->assertSee('RET-HISTORY-12')
        ->call('viewReturnDetail', $return->id)
        ->assertSee('History Return Cable')
        ->assertSee('Same product exchange')
        ->assertSee('Rs 500.00');
});

test('customer bill and return entries open details with actual amounts and print on bills', function () {
    [$customer, $product, $sale] = returnScenario(['paid_amount' => 0, 'due_amount' => 500, 'payment_status' => 'due']);
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'adjust_due', 'Broken connector returned');
    $user = returnWorkflowUser();

    Livewire::actingAs($user)->test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Return value')
        ->assertSee('Rs 500.00')
        ->assertSeeHtml('wire:click="viewReturnDetail('.$return->id.')"')
        ->call('viewReturnDetail', $return->id)
        ->assertSet('returnDetailOpen', true)
        ->assertSee('Broken connector returned')
        ->assertSee('Faulty Product')
        ->call('viewSaleDetail', $sale->id)
        ->assertSee('Invoice Returns History')
        ->assertSee('Net bill after returns');

    Livewire::actingAs($user)->test('pages::parties.customer-transactions')
        ->call('selectCustomer', $customer->id)
        ->call('viewReturnDetail', $return->id)
        ->assertSee('Broken connector returned')
        ->call('viewSaleDetail', $sale->id)
        ->assertSee('Invoice Returns History');

    Livewire::actingAs($user)->test('pages::sales.index')
        ->set('viewingSaleId', $sale->id)
        ->assertSee('Broken connector returned')
        ->assertSee('Due reduced');

    $this->get(route('public.bill', $sale->invoice_no))->assertOk()
        ->assertSee($return->invoice_no)
        ->assertSee('Broken connector returned')
        ->assertSee('Net bill after returns');

    expect(view('partials.a4-invoice', ['sale' => $sale->fresh()->load(['customer', 'items.product', 'payments', 'returns.items.product', 'returns.sale', 'returns.faultyItems']), 'devName' => ''])->render())
        ->toContain($return->invoice_no, 'Broken connector returned');
});

test('customer ledger details cannot open another customers invoice or return', function () {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1]], 'cash_refund');
    $other = Customer::query()->create(['name' => 'Other Customer', 'due_balance' => 0]);

    expect(fn () => Livewire::actingAs(returnWorkflowUser())->test('pages::parties.customers')
        ->call('viewLedger', $other->id)->call('viewReturnDetail', $return->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::actingAs(returnWorkflowUser())->test('pages::parties.customer-transactions')
        ->call('selectCustomer', $other->id)->call('viewSaleDetail', $sale->id))
        ->toThrow(ModelNotFoundException::class);
});

test('returns already sent to suppliers cannot be reversed', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange');
    $return->faultyItems()->update(['status' => 'returned']);

    expect(fn () => $service->revert($return))->toThrow(ValidationException::class);

    expect($return->fresh())->not->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(4)
        ->and(FaultyItem::query()->count())->toBe(1);
});

test('reversing a restock return cannot create negative stock', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1]], 'cash_refund');
    $product->update(['stock_quantity' => 0]);

    expect(fn () => $service->revert($return))->toThrow(ValidationException::class);

    expect($return->fresh())->not->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(0)
        ->and($return->payments()->count())->toBe(1);
});

test('cash refund processing rechecks permission after the page has loaded', function () {
    [$customer, $product, $sale] = returnScenario();
    $user = returnWorkflowUser();
    $component = Livewire::actingAs($user)->test('pages::pos.returns')
        ->call('selectSale', $sale->id)->call('updateReturnQty', $product->id, 1);
    $user->update(['role' => 'sales_staff']);

    $component->call('submitReturn')->assertForbidden();

    expect(SaleReturn::query()->count())->toBe(0);
});

test('sales list return action uses the same condition and refund calculation', function () {
    [$customer, $product, $sale] = returnScenario(['discount_amount' => 100, 'grand_total' => 400, 'paid_amount' => 400]);

    Livewire::actingAs(returnWorkflowUser())->test('pages::sales.index')
        ->set('viewingSaleId', $sale->id)
        ->call('initiateReturn')
        ->call('updateReturnQty', $product->id, 1)
        ->assertSet('returnItems.'.$product->id.'.subtotal', 400.0)
        ->set('returnItems.'.$product->id.'.condition', 'faulty')
        ->call('submitReturn')
        ->assertHasNoErrors();

    expect((float) $sale->returns()->firstOrFail()->refund_amount)->toBe(400.0)
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and(FaultyItem::query()->count())->toBe(1);
});

test('return credit records show their value without reducing original invoice due twice', function () {
    [$customer, $product, $sale] = returnScenario();
    $return = $sale->returns()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'RET-CREDIT-ONLY', 'date' => today(),
        'return_type' => 'bill_credit', 'refund_amount' => 0, 'adjusted_amount' => 500,
        'notes' => 'Return credited on checkout INV-NEW-123.',
    ]);
    $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'refund_price' => 500, 'subtotal' => 500]);

    $component = Livewire::actingAs(returnWorkflowUser())->test('pages::parties.customers')
        ->call('viewLedger', $customer->id)
        ->assertSee('Bill credit')
        ->call('viewReturnDetail', $return->id)
        ->assertSee('INV-NEW-123');

    expect($component->get('selectedCustomerLedger')->firstWhere('type', 'return')['credit'])->toBe(0.0)
        ->and(fn () => app(SaleReturnService::class)->revert($return))->toThrow(ValidationException::class);
});

test('deleting returned bills restores only the stock actually moved and clears return expenses', function (string $type, string $condition) {
    [$customer, $product, $sale] = returnScenario();
    app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => $condition]], $type);

    Livewire::actingAs(returnWorkflowUser())->test('pages::sales.index')
        ->set('viewingSaleId', $sale->id)->call('deleteSale')->assertHasNoErrors();

    expect($product->refresh()->stock_quantity)->toBe(6)
        ->and(SaleReturn::query()->count())->toBe(0)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
})->with([
    ['cash_refund', 'faulty'],
    ['cash_refund', 'restock'],
    ['exchange', 'faulty'],
    ['exchange', 'restock'],
]);

test('checkout return credits cannot exceed discounted original invoice value', function () {
    [$customer, $product, $sale] = returnScenario(['discount_amount' => 100, 'grand_total' => 400, 'paid_amount' => 400]);
    $newProduct = Product::factory()->create(['selling_price' => 1000, 'stock_quantity' => 5, 'is_active' => true]);
    $key = $sale->id.'-'.$product->id;

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.index')
        ->set('customer_id', $customer->id)
        ->call('addToCart', $newProduct->id)
        ->call('addReturnCredit', $sale->items()->firstOrFail()->id)
        ->assertSet('returnCredits.'.$key.'.return_price', 400.0)
        ->call('updateReturnCreditPrice', $key, 900)
        ->call('submitCheckout')
        ->assertHasErrors('returnCredits');

    expect(Sale::query()->count())->toBe(1)
        ->and(SaleReturn::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and($newProduct->refresh()->stock_quantity)->toBe(5);
});

test('checkout using a full return credit can be edited without counting its own return twice', function () {
    [$customer, $product, $sale] = returnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 1000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = returnWorkflowUser();

    Livewire::actingAs($user)->test('pages::pos.index')
        ->set('customer_id', $customer->id)->call('addToCart', $newProduct->id)
        ->call('addReturnCredit', $sale->items()->firstOrFail()->id)
        ->assertSet('cartTotal', 500.0)
        ->call('submitCheckout')->assertHasNoErrors();

    $checkout = Sale::query()->whereKeyNot($sale->id)->firstOrFail();

    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->assertSet('returnCredits.'.$sale->id.'-'.$product->id.'.max', 1)
        ->assertSet('cartTotal', 500.0)
        ->call('submitCheckout')->assertHasNoErrors();

    expect(SaleReturn::query()->count())->toBe(1)
        ->and(Sale::query()->count())->toBe(2)
        ->and($product->refresh()->stock_quantity)->toBe(6)
        ->and($newProduct->refresh()->stock_quantity)->toBe(4)
        ->and((float) $checkout->refresh()->paid_amount)->toBe(500.0)
        ->and((float) $checkout->due_amount)->toBe(0.0);

    $refund = app(SaleReturnService::class)->process($checkout->fresh(), [$newProduct->id => ['quantity' => 1]], 'cash_refund');
    expect((float) $refund->refund_amount)->toBe(1000.0)
        ->and($newProduct->refresh()->stock_quantity)->toBe(5);
});

test('pending cheque returns show a clear validation error without issuing unpaid cash', function () {
    [$customer, $product, $sale] = returnScenario(['paid_amount' => 0, 'due_amount' => 0, 'payment_status' => 'cheque_pending']);
    $sale->payments()->create(['amount' => 500, 'payment_method' => 'cheque', 'date' => today(), 'cheque_status' => 'pending']);

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('selectSale', $sale->id)->call('updateReturnQty', $product->id, 1)
        ->set('returnType', 'cash_refund')->call('submitReturn')
        ->assertHasErrors('returnType')
        ->assertSee('Resolve the pending cheque');

    expect(SaleReturn::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('linked bill credits cannot bypass reversal protection through invoice deletion', function () {
    [$customer, $product, $sale] = returnScenario();
    $sale->returns()->create(['customer_id' => $customer->id, 'invoice_no' => 'RET-LINKED-DELETE', 'date' => today(), 'return_type' => 'bill_credit', 'adjusted_amount' => 500]);

    Livewire::actingAs(returnWorkflowUser())->test('pages::sales.index')
        ->set('viewingSaleId', $sale->id)->call('deleteSale')->assertHasErrors('returnItems');

    expect($sale->fresh())->not->toBeNull()
        ->and(SaleReturn::query()->count())->toBe(1)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('checkout failure rolls back invoice items payments and return stock together', function () {
    [$customer, $product, $sale] = returnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 1000, 'stock_quantity' => 5, 'is_active' => true]);
    $component = Livewire::actingAs(returnWorkflowUser())->test('pages::pos.index')
        ->set('customer_id', $customer->id)->call('addToCart', $newProduct->id)
        ->call('addReturnCredit', $sale->items()->firstOrFail()->id);
    $newProduct->delete();

    expect(fn () => $component->call('submitCheckout'))->toThrow(ModelNotFoundException::class);

    expect(Sale::query()->count())->toBe(1)
        ->and(SaleItem::query()->count())->toBe(1)
        ->and(SaleReturn::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('completed returns expose edit and delete actions and retain identity when condition changes', function () {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund', 'Original reason');
    $number = $return->invoice_no;

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->assertSee('Edit Return')->assertSee('Delete Return')
        ->call('editReturn', $return->id)
        ->assertSet('editingReturnId', $return->id)
        ->assertSet('returnItems.'.$product->id.'.condition', 'faulty')
        ->assertSee('Save Return Changes')
        ->set('returnItems.'.$product->id.'.condition', 'restock')
        ->set('returnNotes', 'Restock condition corrected')
        ->call('submitReturn')->assertHasNoErrors()
        ->assertSet('editingReturnId', null)
        ->assertSee('Restock condition corrected');

    expect($return->refresh()->invoice_no)->toBe($number)
        ->and(SaleReturn::query()->count())->toBe(1)
        ->and($product->refresh()->stock_quantity)->toBe(6)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and((float) $return->refund_amount)->toBe(500.0)
        ->and($return->payments()->count())->toBe(1);
});

test('return condition edits apply exactly the final stock movement', function (string $type, string $from, string $to, int $expected) {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => $from]], $type);
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => $to]], $type);

    expect($product->refresh()->stock_quantity)->toBe($expected)
        ->and(FaultyItem::query()->count())->toBe($to === 'faulty' ? 1 : 0)
        ->and(Expense::query()->count())->toBe($type === 'exchange' && $to === 'faulty' ? 1 : 0)
        ->and(SaleReturn::query()->count())->toBe(1);
})->with([
    ['cash_refund', 'faulty', 'restock', 6],
    ['cash_refund', 'restock', 'faulty', 5],
    ['exchange', 'faulty', 'restock', 5],
    ['exchange', 'restock', 'faulty', 4],
]);

test('notes only edit succeeds when returned restock was already sold', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'cash_refund');
    $product->update(['stock_quantity' => 0]);
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'cash_refund', 'Reason corrected');

    expect($product->refresh()->stock_quantity)->toBe(0)
        ->and($return->refresh()->notes)->toBe('Reason corrected')
        ->and((float) $return->refund_amount)->toBe(500.0);
});

test('failed condition edit rolls back all financial stock and faulty changes', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'cash_refund', 'Keep original');
    $paymentId = $return->payments()->firstOrFail()->id;
    $itemId = $return->items->first()->id;
    $product->update(['stock_quantity' => 0]);

    expect(fn () => $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund', 'Invalid correction'))
        ->toThrow(ValidationException::class);

    expect($product->refresh()->stock_quantity)->toBe(0)
        ->and($return->refresh()->notes)->toBe('Keep original')
        ->and($return->payments()->firstOrFail()->id)->toBe($paymentId)
        ->and($return->items()->firstOrFail()->id)->toBe($itemId)
        ->and(FaultyItem::query()->count())->toBe(0);
});

test('editing return quantity restores only the difference in customer and invoice dues', function () {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 1000, 'grand_total' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'payment_status' => 'due']);
    $sale->items()->update(['quantity' => 2, 'subtotal' => 1000]);
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 2, 'condition' => 'restock']], 'adjust_due');

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('editReturn', $return->id)
        ->assertSet('returnType', 'adjust_due')
        ->call('updateReturnQty', $product->id, 1)
        ->call('submitReturn')->assertHasNoErrors();

    expect((float) $return->refresh()->adjusted_amount)->toBe(500.0)
        ->and((float) $sale->refresh()->due_amount)->toBe(500.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(500.0)
        ->and($sale->payment_status)->toBe('due')
        ->and($product->refresh()->stock_quantity)->toBe(6);
});

test('return edits keep original return and refund dates', function (string $type) {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $this->travelTo(now()->subMonth());
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], $type);
    $date = $return->date->toDateString();
    $this->travelBack();
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], $type, 'Historic correction');

    expect($return->refresh()->date->toDateString())->toBe($date);
    if ($type === 'cash_refund') {
        expect($return->payments()->firstOrFail()->date->toDateString())->toBe($date);
    } else {
        expect(Expense::query()->firstOrFail()->date->toDateString())->toBe($date);
    }
})->with(['cash_refund', 'exchange']);

test('deleting a completed return clears its detail and restores all balances', function () {
    [$customer, $product, $sale] = returnScenario(['paid_amount' => 200, 'due_amount' => 300, 'payment_status' => 'partial']);
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'adjust_due');

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('viewReturnDetail', $return->id)->call('deleteReturn', $return->id)
        ->assertHasNoErrors()->assertSet('returnDetailOpen', false)
        ->assertSet('selectedReturnId', null)->assertDontSee($return->invoice_no);

    expect(SaleReturn::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and((float) $sale->refresh()->due_amount)->toBe(300.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(300.0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('partial returns retain cent accuracy after an earlier return is edited or deleted', function (string $action) {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 300, 'discount_amount' => 200, 'grand_total' => 100, 'paid_amount' => 100]);
    $sale->items()->update(['quantity' => 3, 'selling_price' => 100, 'subtotal' => 300]);
    $service = app(SaleReturnService::class);
    $items = [$product->id => ['quantity' => 1, 'condition' => 'restock']];
    $first = $service->process($sale, $items, 'cash_refund');
    $service->process($sale, $items, 'cash_refund');
    if ($action === 'edit') {
        $service->update($first, $items, 'cash_refund', 'Correct reason');
    } else {
        $service->revert($first);
        $service->process($sale, $items, 'cash_refund');
    }
    $service->process($sale, $items, 'cash_refund');

    expect((float) $sale->returns()->sum('refund_amount'))->toBe(100.0)
        ->and((float) Payment::query()->sum('amount'))->toBe(100.0);
})->with(['edit', 'delete']);

test('return edit and delete actions recheck current permission', function (string $action) {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1]], 'cash_refund');
    $user = returnWorkflowUser();
    $component = Livewire::actingAs($user)->test('pages::pos.returns');
    $user->update(['role' => 'sales_staff']);
    $component->call($action, $return->id)->assertForbidden();

    expect($return->fresh())->not->toBeNull();
})->with(['editReturn', 'deleteReturn']);

test('supplier processed returns cannot be edited or deleted', function (string $action) {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange');
    $return->faultyItems()->update(['status' => 'returned']);

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->assertSee('Already returned to supplier')
        ->call($action, $return->id)->assertHasErrors('returnItems');

    expect($return->fresh())->not->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(4);
})->with(['editReturn', 'deleteReturn']);

test('changing return action replaces refunds and replacement expenses without duplication', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange');
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'cash_refund');

    expect($product->refresh()->stock_quantity)->toBe(6)
        ->and(Expense::query()->count())->toBe(0)
        ->and(FaultyItem::query()->count())->toBe(0)
        ->and((float) Payment::query()->sum('amount'))->toBe(500.0);

    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange');

    expect($product->refresh()->stock_quantity)->toBe(4)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(1)
        ->and(FaultyItem::query()->count())->toBe(1);
});

test('invalid edited quantities and conditions leave the existing return intact', function (string $invalid) {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund');
    $items = [$product->id => ['quantity' => $invalid === 'quantity' ? 2 : 1, 'condition' => $invalid === 'condition' ? 'broken' : 'restock']];

    expect(fn () => $service->update($return, $items, 'cash_refund'))->toThrow(ValidationException::class);

    expect($return->fresh())->not->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and(FaultyItem::query()->count())->toBe(1)
        ->and((float) Payment::query()->sum('amount'))->toBe(500.0);
})->with(['quantity', 'condition']);

test('bill credit edit opens its checkout and removing it reverses the credit stock and balances', function () {
    [$customer, $product, $sale] = returnScenario();
    $newProduct = Product::factory()->create(['selling_price' => 1000, 'stock_quantity' => 5, 'is_active' => true]);
    $user = returnWorkflowUser();
    Livewire::actingAs($user)->test('pages::pos.index')
        ->set('customer_id', $customer->id)->call('addToCart', $newProduct->id)
        ->call('addReturnCredit', $sale->items()->firstOrFail()->id)
        ->call('submitCheckout')->assertHasNoErrors();
    $credit = $sale->returns()->firstOrFail();
    $checkout = Sale::query()->whereKeyNot($sale->id)->firstOrFail();

    Livewire::actingAs($user)->test('pages::pos.returns')
        ->assertSee('Edit / Delete on Bill')
        ->call('editReturn', $credit->id)->assertRedirect(route('pos.index', ['sale' => $checkout->id]));

    Livewire::actingAs($user)->test('pages::pos.index', ['sale' => $checkout])
        ->call('removeReturnCredit', $sale->id.'-'.$product->id)
        ->call('submitCheckout')->assertHasNoErrors();

    expect($credit->fresh())->toBeNull()
        ->and($product->refresh()->stock_quantity)->toBe(5)
        ->and($newProduct->refresh()->stock_quantity)->toBe(4)
        ->and((float) $checkout->refresh()->grand_total)->toBe(1000.0)
        ->and((float) $checkout->due_amount)->toBe(500.0)
        ->and((float) $customer->refresh()->due_balance)->toBe(500.0);
});

test('canceling return edits and opening the edit link never changes the database', function () {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund', 'Unchanged');
    $user = returnWorkflowUser();
    $this->actingAs($user)->get(route('pos.returns', ['edit_return' => $return->id]))
        ->assertOk()->assertSee('Save Return Changes')->assertSee('Returned condition');

    Livewire::actingAs($user)->test('pages::pos.returns')->call('editReturn', $return->id)
        ->set('returnItems.'.$product->id.'.condition', 'restock')
        ->call('cancelReturnEdit')->assertSet('editingReturnId', null)->assertSet('selectedSaleId', null);

    expect($product->refresh()->stock_quantity)->toBe(5)
        ->and($return->refresh()->notes)->toBe('Unchanged')
        ->and(FaultyItem::query()->count())->toBe(1);
});

test('condition corrections preserve historical return valuation instead of silently repricing it', function () {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 1000, 'grand_total' => 900, 'discount_amount' => 100, 'paid_amount' => 900]);
    $sale->items()->update(['quantity' => 2, 'subtotal' => 1000]);
    $return = $sale->returns()->create([
        'customer_id' => $customer->id, 'invoice_no' => 'RET-LEGACY-PRICE', 'date' => today()->subMonth(),
        'return_type' => 'cash_refund', 'refund_amount' => 500, 'adjusted_amount' => 0,
    ]);
    $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'refund_price' => 500, 'subtotal' => 500]);
    $return->faultyItems()->create(['product_id' => $product->id, 'quantity' => 1, 'status' => 'pending']);
    $return->payments()->create(['amount' => 500, 'date' => $return->date, 'payment_method' => 'cash']);

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('editReturn', $return->id)
        ->assertSet('returnItems.'.$product->id.'.subtotal', 500.0)
        ->call('updateReturnQty', $product->id, 0)
        ->call('updateReturnQty', $product->id, 1)
        ->assertSet('returnItems.'.$product->id.'.subtotal', 500.0)
        ->set('returnItems.'.$product->id.'.condition', 'restock')
        ->call('submitReturn')->assertHasNoErrors();

    expect((float) $return->refresh()->refund_amount)->toBe(500.0)
        ->and((float) $return->items()->firstOrFail()->refund_price)->toBe(500.0)
        ->and($product->refresh()->stock_quantity)->toBe(6);
});

test('multiple deleted partial returns reconcile all rounding cents on the remaining return', function () {
    [$customer, $product, $sale] = returnScenario(['subtotal_amount' => 300, 'discount_amount' => 200, 'grand_total' => 100, 'paid_amount' => 100]);
    $sale->items()->update(['quantity' => 30, 'selling_price' => 10, 'subtotal' => 300]);
    $service = app(SaleReturnService::class);
    foreach (range(1, 30) as $iteration) {
        $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund');
    }
    foreach ($sale->returns()->where('refund_amount', 3.33)->limit(5)->get() as $return) {
        $service->revert($return);
    }
    $service->process($sale, [$product->id => ['quantity' => 5, 'condition' => 'faulty']], 'cash_refund');

    expect((float) $sale->returns()->sum('refund_amount'))->toBe(100.0)
        ->and((float) Payment::query()->sum('amount'))->toBe(100.0)
        ->and($product->refresh()->stock_quantity)->toBe(5);
});

test('supplier return processing protects the original customer return from later edits', function () {
    [$customer, $product, $sale] = returnScenario();
    $return = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'exchange');
    $faulty = $return->faultyItems()->firstOrFail();
    $supplier = Supplier::query()->create(['name' => 'Faulty Supplier', 'due_balance' => 0]);

    Livewire::actingAs(returnWorkflowUser())->test('pages::purchases.returns')
        ->set('selectedFaultyItems', [$faulty->id])->set('selectedSupplierId', $supplier->id)
        ->set('returnType', 'exchange')->call('processReturn')->assertHasNoErrors();

    expect($faulty->refresh()->status)->toBe('returned')
        ->and($product->refresh()->stock_quantity)->toBe(5);

    Livewire::actingAs(returnWorkflowUser())->test('pages::pos.returns')
        ->call('deleteReturn', $return->id)->assertHasErrors('returnItems');

    Livewire::actingAs(returnWorkflowUser())->test('pages::sales.index')
        ->set('viewingSaleId', $sale->id)
        ->assertSee('Already returned to supplier')
        ->assertDontSee('Revert Return');
});

test('a supplier cannot process faulty items removed by a customer condition edit', function () {
    [$customer, $product, $sale] = returnScenario();
    $service = app(SaleReturnService::class);
    $return = $service->process($sale, [$product->id => ['quantity' => 1, 'condition' => 'faulty']], 'cash_refund');
    $faultyId = $return->faultyItems()->firstOrFail()->id;
    $supplier = Supplier::query()->create(['name' => 'Stale Faulty Supplier', 'due_balance' => 0]);
    $component = Livewire::actingAs(returnWorkflowUser())->test('pages::purchases.returns')
        ->set('selectedFaultyItems', [$faultyId])->set('selectedSupplierId', $supplier->id);
    $service->update($return, [$product->id => ['quantity' => 1, 'condition' => 'restock']], 'cash_refund');
    $component->call('processReturn')->assertHasErrors('selectedFaultyItems');

    expect(PurchaseReturn::query()->count())->toBe(0)
        ->and($product->refresh()->stock_quantity)->toBe(6);
});
