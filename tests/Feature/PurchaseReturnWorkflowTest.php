<?php

use App\Models\FaultyItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingLedgerService;
use App\Services\ChequePaymentService;
use App\Services\PurchaseReturnService;
use App\Services\SaleReturnService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function purchaseReturnScenario(float $due = 300): array
{
    $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    test()->actingAs($user);
    $supplier = Supplier::query()->create(['name' => 'Return Supplier', 'due_balance' => $due]);
    $product = Product::factory()->create(['name' => 'Returned Charger', 'cost_price' => 100, 'stock_quantity' => 10]);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id, 'invoice_no' => 'PUR-'.Str::uuid(), 'date' => today(),
        'total_amount' => 1000, 'grand_total' => 1000, 'paid_amount' => 1000 - $due, 'due_amount' => $due,
        'payment_status' => $due > 0 ? 'partial' : 'paid',
    ]);
    $item = $purchase->items()->create(['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 150, 'subtotal' => 1000]);

    return [$supplier, $product, $purchase, $item];
}

function purchaseReturnInput(Supplier $supplier, Purchase $purchase, int $itemId, array $overrides = []): array
{
    return array_replace([
        'supplier_id' => $supplier->id, 'purchase_id' => $purchase->id, 'source' => 'purchase',
        'items' => [['id' => $itemId, 'quantity' => 2, 'refund_price' => 100]],
        'replacements' => [], 'return_type' => 'cash_refund', 'balance_action' => 'cash_refund',
        'paid_amount' => 0, 'deduction' => 0, 'notes' => 'Supplier accepted.', 'operation_token' => (string) Str::uuid(),
    ], $overrides);
}

test('purchase returns record real cash inflows and remove only the returned stock', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id));

    expect((float) $return->refund_amount)->toBe(200.0)
        ->and($product->refresh()->stock_quantity)->toBe(8)
        ->and((float) $supplier->refresh()->due_balance)->toBe(300.0)
        ->and((float) $purchase->refresh()->due_amount)->toBe(300.0)
        ->and((float) $return->payments()->sole()->amount)->toBe(200.0);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect((float) $ledger->sum('debit'))->toBe(200.0)
        ->and((float) $ledger->sum('credit'))->toBe(0.0)
        ->and(app(AccountingLedgerService::class)->registerClosingRows($ledger)->sole()['net'])->toBe(200.0);
});

test('deducting due allocates to invoices and refunds only the excess', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(150);
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id, ['return_type' => 'adjust_due']));

    expect((float) $return->adjusted_amount)->toBe(150.0)
        ->and((float) $return->refund_amount)->toBe(50.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0)
        ->and((float) $purchase->refresh()->due_amount)->toBe(0.0)
        ->and($purchase->payment_status)->toBe('paid')
        ->and(PurchaseReturn::dueReductionFor($purchase->id))->toBe(150.0);
});

test('returns can split a refund between cash and an old balance', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id, ['return_type' => 'split', 'deduction' => 75]));
    expect((float) $return->adjusted_amount)->toBe(75.0)
        ->and((float) $return->refund_amount)->toBe(125.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(225.0)
        ->and((float) $purchase->refresh()->due_amount)->toBe(225.0);
});

test('exchanges handle equal higher and lower values with cash partial or full due', function (float $replacementValue, float $cashPaid, string $action, float $expectedRefund, float $expectedDeduction, float $expectedDue) {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $replacementProduct = Product::factory()->create(['cost_price' => 100, 'stock_quantity' => 0]);
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id, [
        'return_type' => 'exchange', 'balance_action' => $action, 'paid_amount' => $cashPaid,
        'replacements' => [['product_id' => $replacementProduct->id, 'quantity' => 1, 'cost_price' => $replacementValue]],
    ]));
    $replacement = $return->replacementPurchase;
    expect((float) $return->refund_amount)->toBe($expectedRefund)
        ->and((float) $return->adjusted_amount)->toBe($expectedDeduction)
        ->and((float) $replacement->due_amount)->toBe($expectedDue)
        ->and((float) $replacement->return_credit_amount)->toBe(min(200.0, $replacementValue))
        ->and((float) $supplier->refresh()->due_balance)->toBe(300.0 - $expectedDeduction + $expectedDue)
        ->and($product->refresh()->stock_quantity)->toBe(8)
        ->and($replacementProduct->refresh()->stock_quantity)->toBe(1)
        ->and((float) $replacement->payments()->sum('amount'))->toBe($cashPaid);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect((float) $ledger->sum('debit'))->toBe($expectedRefund)
        ->and((float) $ledger->sum('credit'))->toBe($cashPaid);
})->with([
    'equal value' => [200, 0, 'cash_refund', 0, 0, 0],
    'higher paid cash' => [350, 150, 'cash_refund', 0, 0, 0],
    'higher partial cash' => [350, 50, 'cash_refund', 0, 0, 100],
    'higher fully due' => [350, 0, 'cash_refund', 0, 0, 150],
    'lower receive cash' => [125, 0, 'cash_refund', 75, 0, 0],
    'lower reduce due' => [125, 0, 'adjust_due', 0, 75, 0],
]);

test('partial faulty returns do not decrement saleable stock and cannot be returned twice', function () {
    [$supplier, $product, $purchase] = purchaseReturnScenario();
    $faulty = FaultyItem::query()->create(['product_id' => $product->id, 'quantity' => 3, 'status' => 'pending']);
    $input = purchaseReturnInput($supplier, $purchase, $faulty->id, ['source' => 'faulty', 'purchase_id' => null]);
    $service = app(PurchaseReturnService::class);
    $service->process($input);
    expect($faulty->refresh()->status)->toBe('pending')->and($product->refresh()->stock_quantity)->toBe(10);
    $input['operation_token'] = (string) Str::uuid();
    expect(fn () => $service->process($input))->toThrow(ValidationException::class);
    $input['items'][0]['quantity'] = 1;
    $service->process($input);
    expect($faulty->refresh()->status)->toBe('returned')->and($product->refresh()->stock_quantity)->toBe(10);
});

test('purchase returns reject invalid stock values suppliers and duplicate quantities atomically', function (string $invalidCase) {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $input = purchaseReturnInput($supplier, $purchase, $item->id);
    match ($invalidCase) {
        'stock' => $product->update(['stock_quantity' => 1]),
        'quantity' => $input['items'][0]['quantity'] = 11,
        'price' => $input['items'][0]['refund_price'] = 101,
        'negative quantity' => $input['items'][0]['quantity'] = -1,
        'fractional quantity' => $input['items'][0]['quantity'] = 1.5,
        'payment' => $input['paid_amount'] = 1,
        'deduction' => [$input['return_type'] = 'split', $input['deduction'] = 201],
        'supplier' => $input['supplier_id'] = Supplier::query()->create(['name' => 'Wrong supplier'])->id,
        'duplicate' => $input['items'][] = $input['items'][0],
        'deleted replacement' => [$input['return_type'] = 'exchange', $input['replacements'] = [['product_id' => 999999, 'quantity' => 1, 'cost_price' => 200]]],
    };
    expect(fn () => app(PurchaseReturnService::class)->process($input))->toThrow(ValidationException::class);
    expect(PurchaseReturn::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(300.0);
})->with(['stock', 'quantity', 'price', 'negative quantity', 'fractional quantity', 'payment', 'deduction', 'supplier', 'duplicate', 'deleted replacement']);

test('a repeated submission is idempotent and later returns respect earlier quantities', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $input = purchaseReturnInput($supplier, $purchase, $item->id);
    $service = app(PurchaseReturnService::class);
    $return = $service->process($input);
    expect($service->process($input)->id)->toBe($return->id)
        ->and(PurchaseReturn::query()->count())->toBe(1)
        ->and($product->refresh()->stock_quantity)->toBe(8);
    $input['operation_token'] = (string) Str::uuid();
    $input['items'][0]['quantity'] = 9;
    expect(fn () => $service->process($input))->toThrow(ValidationException::class);
});

test('invoice discounts are reflected in return values', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $purchase->update(['discount' => 100, 'grand_total' => 900]);
    $input = purchaseReturnInput($supplier, $purchase, $item->id);
    expect(fn () => app(PurchaseReturnService::class)->process($input))->toThrow(ValidationException::class);
    $input['items'][0]['refund_price'] = 90;
    expect((float) app(PurchaseReturnService::class)->process($input)->refund_amount)->toBe(180.0);
});

test('a later cheque bounce preserves return due deductions', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(300);
    $purchase->update(['paid_amount' => 200, 'payment_status' => 'cheque_pending']);
    $cheque = $purchase->payments()->create(['amount' => 500, 'payment_method' => 'cheque', 'date' => today(), 'cheque_status' => 'pending', 'cheque_type' => 'own']);
    app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id, ['return_type' => 'adjust_due']));
    app(ChequePaymentService::class)->markReturned($cheque);
    expect((float) $purchase->refresh()->due_amount)->toBe(600.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(600.0);
});

test('the return form previews and saves a partial payment exchange and renders its history', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    Livewire::test('pages::purchases.returns')
        ->set('selectedSupplierId', $supplier->id)->set('source', 'purchase')
        ->set('selectedPurchaseId', $purchase->id)
        ->set('returnItems.'.$item->id.'.quantity', 2)
        ->set('returnType', 'exchange')
        ->set('replacementItems.'.$product->id.'.cost_price', 150)
        ->set('paidAmount', 40)
        ->assertSee('We owe supplier the difference')
        ->assertSee('New due from this exchange:')
        ->call('processReturn')->assertHasNoErrors()->assertSee('Return saved')->assertSee('Remaining exchange due:');
    $return = PurchaseReturn::query()->sole();
    expect((float) $return->replacementPurchase->due_amount)->toBe(60.0)
        ->and($product->refresh()->stock_quantity)->toBe(10);
});

test('purchase return access is limited to staff who can add purchases', function () {
    $this->actingAs(User::factory()->create(['role' => 'cashier']));
    $this->get(route('purchases.returns'))->assertForbidden();
});

test('purchases linked to returns cannot be edited and return history appears in supplier ledger', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id, ['return_type' => 'adjust_due']));
    $this->get(route('purchases.edit', $purchase))->assertForbidden();
    Livewire::test('pages::parties.suppliers')->call('viewLedger', $supplier->id)->assertSee($return->invoice_no)->assertSee('Purchase return');
});

test('outstanding exchange due can be paid later without recording return credit as cash', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(0);
    $service = app(PurchaseReturnService::class);
    $return = $service->process(purchaseReturnInput($supplier, $purchase, $item->id, [
        'return_type' => 'exchange', 'replacements' => [['product_id' => $product->id, 'quantity' => 2, 'cost_price' => 150]],
    ]));
    $token = (string) Str::uuid();
    $service->payExchangeDue($return, 40, $token);
    $service->payExchangeDue($return, 40, $token);
    expect((float) $return->replacementPurchase->due_amount)->toBe(60.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(60.0)
        ->and((float) $return->replacementPurchase->paid_amount)->toBe(240.0)
        ->and((float) $return->replacementPurchase->payments()->sum('amount'))->toBe(40.0);
    expect(fn () => $service->payExchangeDue($return, 61, (string) Str::uuid()))->toThrow(ValidationException::class);
    Livewire::test('pages::purchases.returns')->set('exchangePayments.'.$return->id, 60)
        ->call('payExchangeDue', $return->id)->assertHasNoErrors();
    expect((float) $return->replacementPurchase->refresh()->due_amount)->toBe(0.0)
        ->and((float) $supplier->refresh()->due_balance)->toBe(0.0);
    $ledger = app(AccountingLedgerService::class)->transactions(today()->toDateString(), today()->toDateString());
    expect((float) $ledger->sum('credit'))->toBe(100.0)->and((float) $ledger->sum('debit'))->toBe(0.0);
});

test('a partial faulty supplier return protects the customer return from being reverted', function () {
    [$supplier, $product, $purchase] = purchaseReturnScenario();
    $sale = Sale::query()->create([
        'invoice_no' => 'SALE-PARTIAL-FAULTY', 'date' => today(), 'subtotal_amount' => 300, 'grand_total' => 300,
        'paid_amount' => 300, 'due_amount' => 0, 'payment_status' => 'paid', 'profit' => 0,
    ]);
    $sale->items()->create(['product_id' => $product->id, 'quantity' => 3, 'selling_price' => 100, 'cost_price' => 100, 'subtotal' => 300]);
    $saleReturn = app(SaleReturnService::class)->process($sale, [$product->id => ['quantity' => 3, 'condition' => 'faulty']], 'cash_refund');
    $faulty = $saleReturn->faultyItems()->sole();
    app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $faulty->id, ['source' => 'faulty', 'purchase_id' => null]));
    expect($faulty->refresh()->status)->toBe('pending');
    expect(fn () => app(SaleReturnService::class)->revert($saleReturn))->toThrow(ValidationException::class);
});

test('return history and cash receipts appear in supplier transactions and receives reports', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $return = app(PurchaseReturnService::class)->process(purchaseReturnInput($supplier, $purchase, $item->id));
    Livewire::test('pages::parties.supplier-transactions')->call('selectSupplier', $supplier->id)->assertSee($return->invoice_no)->assertSee('Cash received: Rs 200.00');
    Livewire::test('pages::reports.receives')->assertSee($return->invoice_no);
});

test('discounted returns reconcile rounding cents across repeated partial returns', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(0);
    $purchase->update(['total_amount' => 300, 'grand_total' => 100, 'discount' => 200, 'paid_amount' => 100]);
    $item->update(['quantity' => 3, 'subtotal' => 300]);
    $service = app(PurchaseReturnService::class);
    foreach (range(1, 3) as $iteration) {
        $input = purchaseReturnInput($supplier, $purchase, $item->id, ['items' => [['id' => $item->id, 'quantity' => 1, 'refund_price' => 33.33]]]);
        $service->process($input);
    }
    expect((float) PurchaseReturn::query()->sum('refund_amount'))->toBe(100.0)
        ->and((float) Payment::query()->sum('amount'))->toBe(100.0);
});

test('a full discounted return preview matches the saved total including rounding', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(0);
    $purchase->update(['total_amount' => 300, 'grand_total' => 100, 'discount' => 200, 'paid_amount' => 100]);
    $item->update(['quantity' => 3, 'subtotal' => 300]);
    Livewire::test('pages::purchases.returns')->set('source', 'purchase')->set('selectedSupplierId', $supplier->id)
        ->set('selectedPurchaseId', $purchase->id)->set('returnItems.'.$item->id.'.quantity', 3)
        ->set('returnType', 'cash_refund')->assertSee('Rs 100.00')->call('processReturn')->assertHasNoErrors();
    expect((float) PurchaseReturn::query()->sole()->refund_amount)->toBe(100.0);
});

test('products can be found across supplier invoices by name sku and barcode', function (string $search) {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $product->update(['name' => 'Searchable Watch', 'sku' => 'WATCH-123', 'barcode' => '987654321', 'is_active' => false]);
    $otherPurchase = $purchase->replicate();
    $otherPurchase->invoice_no = 'PUR-OTHER-SEARCH';
    $otherPurchase->save();
    $otherItem = $otherPurchase->items()->create($item->only(['product_id', 'quantity', 'cost_price', 'selling_price', 'subtotal']));
    $wrongSupplier = Supplier::query()->create(['name' => 'Other supplier']);
    $wrongPurchase = $purchase->replicate();
    $wrongPurchase->fill(['supplier_id' => $wrongSupplier->id, 'invoice_no' => 'PUR-WRONG-SUPPLIER'])->save();
    $wrongPurchase->items()->create($item->only(['product_id', 'quantity', 'cost_price', 'selling_price', 'subtotal']));

    $component = Livewire::test('pages::purchases.returns')->set('source', 'purchase')
        ->set('selectedSupplierId', $supplier->id)->set('purchaseProductSearch', $search)
        ->assertSee($purchase->invoice_no)->assertSee($otherPurchase->invoice_no);

    expect($component->instance()->purchaseProductResults->pluck('id')->all())->toBe([$otherItem->id, $item->id]);
    $component->call('selectPurchaseProduct', $otherItem->id)
        ->assertSet('selectedPurchaseId', $otherPurchase->id)
        ->assertSet('purchaseProductSearch', '')
        ->assertSet('returnItemSearch', 'WATCH-123')
        ->assertSeeHtml('purchase-return-line-'.$otherItem->id);
})->with(['Searchable', 'WATCH-123', '987654321']);

test('product search is bounded and requires a selected supplier and two characters', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    foreach (range(1, 20) as $index) {
        $purchase->items()->create($item->only(['product_id', 'quantity', 'cost_price', 'selling_price', 'subtotal']));
    }
    $component = Livewire::test('pages::purchases.returns')->set('source', 'purchase')->set('purchaseProductSearch', 'Returned');
    expect($component->instance()->purchaseProductResults)->toHaveCount(0);
    $component->set('selectedSupplierId', $supplier->id)->set('purchaseProductSearch', 'R');
    expect($component->instance()->purchaseProductResults)->toHaveCount(0);
    $component->set('purchaseProductSearch', '  Returned  ');
    expect($component->instance()->purchaseProductResults)->toHaveCount(15);
    $component->set('purchaseProductSearch', 'no-such-product')->assertSee('No purchased products match your search for this supplier.');
});

test('a product result from another supplier cannot select its invoice', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    $otherSupplier = Supplier::query()->create(['name' => 'Other supplier']);
    $component = Livewire::test('pages::purchases.returns')->set('source', 'purchase')
        ->set('selectedSupplierId', $otherSupplier->id);
    expect(fn () => $component->call('selectPurchaseProduct', $item->id))->toThrow(ModelNotFoundException::class);
});

test('large invoices render twenty lines at a time and keep quantities across pages and searches', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario(0);
    $products = Product::factory()->count(44)->create(['cost_price' => 100, 'stock_quantity' => 10]);
    $items = $products->map(fn (Product $product) => $purchase->items()->create([
        'product_id' => $product->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 150, 'subtotal' => 1000,
    ]));
    $purchase->update(['total_amount' => 45000, 'grand_total' => 45000, 'paid_amount' => 45000]);
    $lastItem = $items->last();
    $products->last()->update(['barcode' => '1234567890123']);
    $component = Livewire::test('pages::purchases.returns')->set('source', 'purchase')
        ->set('selectedSupplierId', $supplier->id)->set('selectedPurchaseId', $purchase->id)
        ->assertSeeHtml('purchase-return-line-'.$item->id)
        ->assertDontSeeHtml('purchase-return-line-'.$lastItem->id);
    expect($component->instance()->visiblePurchaseLines->count())->toBe(20)
        ->and($component->get('returnItems'))->toHaveCount(20);

    $component->set('returnItems.'.$item->id.'.quantity', 1)
        ->call('setPage', 3, 'returnItemsPage')->assertSeeHtml('purchase-return-line-'.$lastItem->id)
        ->set('returnItems.'.$lastItem->id.'.quantity', 2)
        ->set('returnItemSearch', $products->last()->barcode)
        ->assertSet('paginators.returnItemsPage', 1)->assertSet('returnItems.'.$item->id.'.quantity', 1)
        ->assertSeeHtml('purchase-return-line-'.$lastItem->id)
        ->assertDontSeeHtml('purchase-return-line-'.$item->id);
    expect($component->instance()->summary['returned'])->toBe(300)
        ->and($component->instance()->visiblePurchaseLines->total())->toBe(1);
    $component->set('returnItemSearch', 'missing-invoice-product')->assertSee('No invoice products match your search.')
        ->set('returnItemSearch', '')->assertSeeHtml('purchase-return-line-'.$item->id)
        ->set('returnType', 'cash_refund')->call('processReturn')->assertHasNoErrors();
    expect((float) PurchaseReturn::query()->sole()->refund_amount)->toBe(300.0)
        ->and($product->refresh()->stock_quantity)->toBe(9)
        ->and($products->last()->refresh()->stock_quantity)->toBe(8);
});

test('selecting products from the current invoice keeps entered quantities and changing supplier clears search', function () {
    [$supplier, $product, $purchase, $item] = purchaseReturnScenario();
    Livewire::test('pages::purchases.returns')->set('source', 'purchase')->set('selectedSupplierId', $supplier->id)
        ->set('selectedPurchaseId', $purchase->id)->set('returnItems.'.$item->id.'.quantity', 2)
        ->call('selectPurchaseProduct', $item->id)->assertSet('returnItems.'.$item->id.'.quantity', 2)
        ->set('purchaseProductSearch', 'Returned')->set('selectedSupplierId', Supplier::query()->create(['name' => 'Other supplier'])->id)
        ->assertSet('selectedPurchaseId', null)->assertSet('returnItems', [])
        ->assertSet('returnItemSearch', '')->assertSet('purchaseProductSearch', '');
});
