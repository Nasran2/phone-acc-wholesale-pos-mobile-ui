<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\FaultyItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    /**
     * @param  array<int|string, array{name?: string|null, sku?: string|null, quantity: int|float|string, refund_price?: int|float|string, condition?: string, max?: int|float|string, subtotal?: int|float|string}>  $returnItems
     */
    public function process(Sale $sale, array $returnItems, string $returnType, ?string $notes = null, bool $allowPriceReduction = false): SaleReturn
    {
        $this->validateReturnInput($returnItems, $returnType, $notes);

        return DB::transaction(function () use ($sale, $returnItems, $returnType, $notes, $allowPriceReduction): SaleReturn {
            $sale = $this->lockSaleAndCustomer($sale->id);
            $customer = $sale->customer;
            ksort($returnItems);
            foreach ($returnItems as &$item) {
                $item['condition'] ??= $returnType === 'exchange' ? 'faulty' : 'restock';
            }
            unset($item);
            $itemsToReturn = $this->normaliseItems($sale, $returnItems, $allowPriceReduction);
            $refundTotal = round((float) collect($itemsToReturn)->sum('subtotal'), 2);

            if ($refundTotal <= 0) {
                throw ValidationException::withMessages([
                    'returnItems' => __('Select at least one product quantity to return.'),
                ]);
            }

            [$adjustedAmount, $refundAmount] = $this->amountsFor($sale, $refundTotal, $returnType);
            $this->validateRefundAvailability($sale, $refundAmount);
            $returnNo = $this->nextReturnNumber();

            $return = SaleReturn::query()->create([
                'sale_id' => $sale->id,
                'customer_id' => $customer?->id,
                'invoice_no' => $returnNo,
                'date' => today()->toDateString(),
                'return_type' => $returnType,
                'refund_amount' => $refundAmount,
                'adjusted_amount' => $adjustedAmount,
                'notes' => $notes ?: 'POS Customer Return request.',
            ]);

            foreach ($itemsToReturn as $item) {
                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'refund_price' => $item['refund_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                $this->moveReturnedStock($item, $returnType, $return->id);
            }

            if ($adjustedAmount > 0) {
                $this->applyDueAdjustment($return, $sale, $customer, $adjustedAmount);
            }

            if ($refundAmount > 0) {
                $return->payments()->create([
                    'amount' => $refundAmount,
                    'payment_method' => 'cash',
                    'date' => today()->toDateString(),
                    'notes' => 'Cash refund for sale return '.$returnNo,
                ]);
            }

            if ($returnType === 'exchange') {
                $this->recordExchangeExpense($return, $itemsToReturn);
            }

            ActivityLogger::log('pos_return', "Processed Customer Return {$returnNo} for invoice {$sale->invoice_no}. Total value: Rs {$refundTotal}.");

            return $return->load(['sale', 'customer', 'items.product', 'faultyItems']);
        });
    }

    /**
     * @param  array<int|string, array{quantity: int|float|string, condition?: string}>  $returnItems
     */
    private function validateReturnInput(array $returnItems, string $returnType, ?string $notes): void
    {
        if (! in_array($returnType, ['cash_refund', 'adjust_due', 'exchange'], true)) {
            throw ValidationException::withMessages([
                'returnType' => __('Select a valid return type.'),
            ]);
        }

        Validator::make(['returnItems' => $returnItems, 'notes' => $notes], [
            'returnItems' => ['required', 'array'],
            'returnItems.*' => ['array'],
            'returnItems.*.quantity' => ['required', 'integer', 'min:0'],
            'returnItems.*.condition' => ['sometimes', 'required', 'in:faulty,restock'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

    }

    private function validateRefundAvailability(Sale $sale, float $refundAmount): void
    {
        if ($refundAmount > $this->availableRefund($sale)) {
            throw ValidationException::withMessages([
                'returnType' => $sale->payments()->where('payment_method', 'cheque')->where('cheque_status', 'pending')->exists()
                    ? __('Resolve the pending cheque before issuing a cash refund for this invoice.')
                    : __('The cash refund exceeds the remaining paid amount. Use Reduce invoice due for unpaid items.'),
            ]);
        }
    }

    public function ensureEditable(SaleReturn $return): void
    {
        $return->loadMissing('faultyItems');

        if ($return->return_type === 'bill_credit' || $return->faultyItems->contains('status', 'returned')) {
            throw ValidationException::withMessages([
                'returnItems' => __('This return is linked to another bill or supplier return and cannot be changed here.'),
            ]);
        }
    }

    /**
     * @param  array<int|string, array{quantity: int|float|string, condition?: string}>  $returnItems
     */
    public function update(SaleReturn $return, array $returnItems, string $returnType, ?string $notes = null, bool $allowPriceReduction = false): SaleReturn
    {
        $this->validateReturnInput($returnItems, $returnType, $notes);

        return DB::transaction(function () use ($return, $returnItems, $returnType, $notes, $allowPriceReduction): SaleReturn {
            $sale = $this->lockSaleAndCustomer($return->sale_id);
            $customer = $sale->customer;
            $return = SaleReturn::query()->lockForUpdate()->findOrFail($return->id);
            $return->load('items');
            $return->setRelation('faultyItems', $return->faultyItems()->lockForUpdate()->get());
            $this->ensureEditable($return);
            $originalDue = (float) $sale->due_amount;
            $oldAdjustment = (float) $return->adjusted_amount;
            $sourceAdjustment = $return->due_allocations === null ? $oldAdjustment
                : (float) collect($return->due_allocations['sales'] ?? [])->where('sale_id', $sale->id)->sum('amount');
            $sale->due_amount = round($originalDue + $sourceAdjustment, 2);
            if ($customer) {
                $virtualCustomer = clone $customer;
                $virtualCustomer->due_balance = round((float) $customer->due_balance + $oldAdjustment, 2);
                $sale->setRelation('customer', $virtualCustomer);
            }
            $sale->setRelation('returns', $sale->returns()->with('items')->whereKeyNot($return->id)->get());
            ksort($returnItems);
            foreach ($returnItems as $productId => &$input) {
                $input['condition'] ??= $return->faultyItems->contains('product_id', $productId) ? 'faulty' : 'restock';
            }
            unset($input);
            $items = $this->normaliseItems($sale, $returnItems, $allowPriceReduction);
            foreach ($items as &$item) {
                $previousItems = $return->items->where('product_id', $item['product_id']);
                if ($item['quantity'] === (int) $previousItems->sum('quantity')
                    && (! $allowPriceReduction || round((float) ($returnItems[$item['product_id']]['refund_price'] ?? $item['refund_price']), 2) === round((float) $previousItems->first()->refund_price, 2))) {
                    $item['refund_price'] = (float) $previousItems->first()->refund_price;
                    $item['subtotal'] = round((float) $previousItems->sum('subtotal'), 2);
                }
            }
            unset($item);
            $total = round((float) collect($items)->sum('subtotal'), 2);

            if ($total <= 0) {
                throw ValidationException::withMessages(['returnItems' => __('Select at least one product quantity to return.')]);
            }

            [$adjustment, $refund] = $this->amountsFor($sale, $total, $returnType);
            $this->validateRefundAvailability($sale, $refund);
            $stockChanges = [];

            foreach ($return->items as $item) {
                $condition = $return->faultyItems->contains('product_id', $item->product_id) ? 'faulty' : 'restock';
                $stockChanges[$item->product_id] = ($stockChanges[$item->product_id] ?? 0)
                    - $this->stockEffect($return->return_type, $condition, $item->quantity);
            }

            foreach ($items as $item) {
                $stockChanges[$item['product_id']] = ($stockChanges[$item['product_id']] ?? 0)
                    + $this->stockEffect($returnType, $item['condition'], $item['quantity']);
            }

            ksort($stockChanges);
            foreach ($stockChanges as $productId => $quantity) {
                $product = Product::withTrashed()->lockForUpdate()->find($productId);
                if (! $product) {
                    throw ValidationException::withMessages(['returnItems' => __('A returned product is no longer available.')]);
                }
                if ($product->stock_quantity + $quantity < 0) {
                    throw ValidationException::withMessages(['returnItems' => __('Not enough stock is available to change the returned condition or quantity.')]);
                }
                if ($quantity !== 0) {
                    $product->increment('stock_quantity', $quantity);
                }
            }

            $sale->due_amount = $originalDue;
            $sale->setRelation('customer', $customer);
            $replaceAllocation = round($oldAdjustment - $adjustment, 2) !== 0.0;
            if ($replaceAllocation && $oldAdjustment > 0) {
                $this->restoreDueAdjustment($return, $sale, $customer);
            }

            $return->payments()->delete();
            $return->faultyItems()->delete();
            $return->items()->delete();
            Expense::query()->where('reference', $return->invoice_no)->where('category', 'Product Return Replacement Cost')->delete();
            $return->update(['return_type' => $returnType, 'adjusted_amount' => $adjustment, 'refund_amount' => $refund, 'notes' => $notes]);
            if ($replaceAllocation) {
                $return->update(['due_allocations' => null]);
                if ($adjustment > 0) {
                    $this->applyDueAdjustment($return, $sale->refresh(), $customer?->refresh(), $adjustment);
                }
            }

            foreach ($items as $item) {
                $return->items()->create([
                    'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
                    'refund_price' => $item['refund_price'], 'subtotal' => $item['subtotal'],
                ]);
                if ($item['condition'] === 'faulty') {
                    $return->faultyItems()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'status' => 'pending']);
                }
            }
            if ($refund > 0) {
                $return->payments()->create([
                    'amount' => $refund, 'payment_method' => 'cash', 'date' => $return->date->toDateString(),
                    'notes' => 'Cash refund for sale return '.$return->invoice_no,
                ]);
            }
            if ($returnType === 'exchange') {
                $this->recordExchangeExpense($return, $items);
            }
            ActivityLogger::log('pos_return_edit', "Updated Customer Return {$return->invoice_no} for invoice {$sale->invoice_no}.");

            return $return->load(['sale', 'customer', 'items.product', 'faultyItems']);
        });
    }

    private function stockEffect(string $type, string $condition, int $quantity): int
    {
        return $type === 'exchange' ? ($condition === 'faulty' ? -$quantity : 0) : ($condition === 'restock' ? $quantity : 0);
    }

    /**
     * @param  array<int|string, array{quantity: int|float|string, refund_price?: int|float|string, condition?: string, subtotal?: int|float|string}>  $returnItems
     * @return array<int, array{product_id: int, quantity: int, refund_price: float, subtotal: float, unit_cost: float, condition: string}>
     */
    private function normaliseItems(Sale $sale, array $returnItems, bool $allowPriceReduction = false): array
    {
        $items = [];
        $availableItems = $this->availableItems($sale);

        foreach ($returnItems as $productId => $item) {
            if (filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages([
                    'returnItems' => __('One of the selected products is invalid.'),
                ]);
            }

            $quantity = max(0, (int) ($item['quantity'] ?? 0));

            if ($quantity === 0) {
                continue;
            }

            $productId = (int) $productId;
            $availableItem = $availableItems[$productId] ?? null;
            $maxReturnable = $availableItem['max'] ?? 0;

            if ($quantity > $maxReturnable) {
                throw ValidationException::withMessages([
                    'returnItems' => __('Return quantity exceeds the invoice balance for one or more products.'),
                ]);
            }

            if (! $availableItem) {
                throw ValidationException::withMessages([
                    'returnItems' => __('One of the selected products is not on this invoice.'),
                ]);
            }

            $refundPrice = $availableItem['refund_price'];
            $subtotal = $this->subtotalFor($availableItem, $quantity);
            if ($allowPriceReduction && isset($item['refund_price']) && (float) $item['refund_price'] < $refundPrice) {
                $refundPrice = round(max(0, (float) $item['refund_price']), 2);
                $subtotal = round($quantity * $refundPrice, 2);
            }

            $items[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'refund_price' => $refundPrice,
                'subtotal' => $subtotal,
                'unit_cost' => $availableItem['unit_cost'],
                'condition' => $item['condition'] ?? 'restock',
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{name: string, sku: string|null, quantity: int, refund_price: float, line_value: float, sold_quantity: int, returned_quantity: int, returned_value: float, reconcile_value: bool, max: int, subtotal: float, condition: string, stock: int, unit_cost: float}>
     */
    public function availableItems(Sale $sale): array
    {
        $sale->loadMissing(['items.product', 'returns.items']);
        $soldItems = $sale->items->where('quantity', '>', 0);
        $subtotal = (float) $soldItems->sum('subtotal');
        $billCredits = abs((float) $sale->items->where('quantity', '<', 0)->sum('subtotal'));
        $valueRatio = $subtotal > 0 ? max(0, (float) $sale->grand_total + $billCredits) / $subtotal : 0;
        $returnedItems = $sale->returns->flatMap->items;
        $items = [];
        $cumulativeSubtotal = 0.0;
        $allocatedValue = 0.0;

        foreach ($soldItems->groupBy('product_id') as $productId => $lines) {
            $cumulativeSubtotal += (float) $lines->sum('subtotal');
            $lineValue = round($cumulativeSubtotal * $valueRatio, 2) - $allocatedValue;
            $allocatedValue += $lineValue;
            $product = $lines->first()->product;
            $soldQuantity = (int) $lines->sum('quantity');
            $returnedQuantity = (int) $returnedItems->where('product_id', $productId)->sum('quantity');
            $returnedValue = (float) $returnedItems->where('product_id', $productId)->sum('subtotal');
            $maxReturnable = max(0, $soldQuantity - $returnedQuantity);

            if (! $product || $maxReturnable === 0) {
                continue;
            }

            $items[(int) $productId] = [
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => 0,
                'refund_price' => round($lineValue / $soldQuantity, 2),
                'line_value' => $lineValue,
                'sold_quantity' => $soldQuantity,
                'returned_quantity' => $returnedQuantity,
                'returned_value' => $returnedValue,
                'reconcile_value' => $returnedItems->where('product_id', $productId)->every(
                    fn ($item): bool => round((float) $item->refund_price, 2) === round($lineValue / $soldQuantity, 2)
                ),
                'max' => $maxReturnable,
                'subtotal' => 0.0,
                'condition' => 'restock',
                'stock' => (int) $product->stock_quantity,
                'unit_cost' => round((float) $lines->sum(fn ($line): float => (float) $line->cost_price * $line->quantity) / $soldQuantity, 2),
            ];
        }

        return $items;
    }

    public function availableRefund(Sale $sale, array $excludedReturnIds = []): float
    {
        $sale->loadMissing(['items', 'returns']);
        $billCredits = abs((float) $sale->items->where('quantity', '<', 0)->sum('subtotal'));
        $previousRefunds = (float) $sale->returns->sum('refund_amount');
        $previousCredits = (float) $sale->returns->where('return_type', 'bill_credit')->sum('adjusted_amount');

        return round(max(0, (float) $sale->paid_amount + $billCredits + $this->dueReductionFor($sale, $excludedReturnIds, externalOnly: true) - $previousRefunds - $previousCredits), 2);
    }

    /**
     * @param  array{line_value: float, sold_quantity: int, returned_quantity: int, returned_value: float, reconcile_value: bool}  $item
     */
    public function subtotalFor(array $item, int $quantity): float
    {
        $unitValue = $item['line_value'] / $item['sold_quantity'];

        if ($quantity === 0) {
            return 0.0;
        }

        $expectedReturnedValue = round($item['returned_quantity'] * $unitValue, 2);
        $roundingCorrection = round($expectedReturnedValue - $item['returned_value'], 2);
        $subtotal = round(($item['returned_quantity'] + $quantity) * $unitValue, 2) - $expectedReturnedValue;

        return round($subtotal + (($item['reconcile_value'] ?? false) ? $roundingCorrection : 0), 2);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function amountsFor(Sale $sale, float $refundTotal, string $returnType): array
    {
        if ($returnType === 'adjust_due') {
            $availableDue = $sale->customer ? (float) $sale->customer->due_balance : (float) $sale->due_amount;
            if ($availableDue <= 0) {
                throw ValidationException::withMessages([
                    'returnType' => __('This customer has no outstanding due to reduce.'),
                ]);
            }
            $adjustedAmount = min($refundTotal, $availableDue);

            return [$adjustedAmount, round(max(0, $refundTotal - $adjustedAmount), 2)];
        }

        if ($returnType === 'cash_refund') {
            return [0.00, $refundTotal];
        }

        return [0.00, 0.00];
    }

    /**
     * @param  array{product_id: int, quantity: int, unit_cost: float, condition: string}  $item
     */
    private function moveReturnedStock(array $item, string $returnType, int $saleReturnId): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($item['product_id']);

        if ($returnType === 'exchange' && $item['condition'] === 'faulty') {
            if ($product->stock_quantity < $item['quantity']) {
                throw ValidationException::withMessages([
                    'returnType' => __('Not enough same-product stock is available for this exchange. Use due adjustment or cash refund instead.'),
                ]);
            }

            $product->decrement('stock_quantity', $item['quantity']);
        }

        if ($item['condition'] === 'faulty') {
            FaultyItem::create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'source_sale_return_id' => $saleReturnId,
                'status' => 'pending',
            ]);
        } elseif ($returnType !== 'exchange') {
            $product->increment('stock_quantity', $item['quantity']);
        }
    }

    private function lockSaleAndCustomer(int $saleId): Sale
    {
        $sale = Sale::query()->findOrFail($saleId);
        $customer = $sale->customer_id ? Customer::query()->lockForUpdate()->findOrFail($sale->customer_id) : null;
        $sale = Sale::query()->lockForUpdate()->findOrFail($saleId);
        $sale->setRelation('customer', $customer);

        return $sale;
    }

    private function setInvoiceDue(Sale $sale, float $due): void
    {
        $pendingCheque = $sale->payments()->where('payment_method', 'cheque')->where('cheque_status', 'pending')->exists();
        $sale->update([
            'due_amount' => round(max(0, $due), 2),
            'payment_status' => $due <= 0 ? ($pendingCheque ? 'cheque_pending' : 'paid')
                : (((float) $sale->paid_amount > 0 || $pendingCheque) ? 'partial' : 'due'),
        ]);
    }

    private function applyDueAdjustment(SaleReturn $return, Sale $source, ?Customer $customer, float $amount): void
    {
        $invoices = $customer
            ? Sale::query()->where('customer_id', $customer->id)->where('due_amount', '>', 0)->orderBy('id')->lockForUpdate()->get()
            : collect([$source]);
        $invoices = $invoices->sortBy(fn (Sale $sale): string => ($sale->id === $source->id ? '0' : '1').$sale->date->format('Ymd').str_pad((string) $sale->id, 20, '0', STR_PAD_LEFT));
        $remaining = $amount;
        $allocations = [];
        foreach ($invoices as $invoice) {
            $applied = round(min($remaining, (float) $invoice->due_amount), 2);
            if ($applied <= 0) {
                continue;
            }
            $this->setInvoiceDue($invoice, (float) $invoice->due_amount - $applied);
            $allocations[] = ['sale_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no, 'amount' => $applied];
            $remaining = round($remaining - $applied, 2);
        }
        if ($customer) {
            $customer->update(['due_balance' => round(max(0, (float) $customer->due_balance - $amount), 2)]);
        }
        $return->update(['due_allocations' => [
            'sales' => $allocations, 'sale_ids' => array_column($allocations, 'sale_id'), 'account_amount' => $remaining,
        ]]);
    }

    private function restoreDueAdjustment(SaleReturn $return, Sale $source, ?Customer $customer): void
    {
        $allocations = $return->due_allocations['sales'] ?? ($return->due_allocations === null
            ? [['sale_id' => $source->id, 'amount' => (float) $return->adjusted_amount]] : []);
        foreach (collect($allocations)->sortBy('sale_id') as $allocation) {
            $invoice = Sale::query()->lockForUpdate()->find($allocation['sale_id']);
            if (! $invoice || $invoice->customer_id !== $source->customer_id) {
                throw ValidationException::withMessages(['returnItems' => __('An invoice credited by this return is missing or belongs to another customer.')]);
            }
            $this->setInvoiceDue($invoice, (float) $invoice->due_amount + (float) $allocation['amount']);
        }
        if ($customer) {
            $customer->update(['due_balance' => round((float) $customer->due_balance + (float) $return->adjusted_amount, 2)]);
        }
    }

    public function releaseCheckoutDueAdjustment(SaleReturn $return, Sale $checkout): void
    {
        DB::transaction(function () use ($return, $checkout): void {
            $source = $this->lockSaleAndCustomer($return->sale_id);
            $return = SaleReturn::query()->lockForUpdate()->findOrFail($return->id);
            $this->ensureEditable($return);
            if ($return->return_type !== 'adjust_due' || $return->checkout_sale_id !== $checkout->id) {
                throw ValidationException::withMessages(['returnCredits' => __('This return does not belong to the bill being edited.')]);
            }
            $this->restoreDueAdjustment($return, $source, $source->customer);
            $return->update(['adjusted_amount' => 0, 'due_allocations' => ['sales' => [], 'sale_ids' => [], 'account_amount' => 0]]);
        });
    }

    /** @param array<int> $excludedReturnIds */
    public function dueReductionFor(Sale $sale, array $excludedReturnIds = [], bool $externalOnly = false): float
    {
        return round((float) SaleReturn::query()->where('return_type', 'adjust_due')
            ->whereNotIn('id', $excludedReturnIds)
            ->when($externalOnly, fn ($query) => $query->where('sale_id', '!=', $sale->id))
            ->where(function ($query) use ($sale): void {
                $query->whereJsonContains('due_allocations->sale_ids', $sale->id)
                    ->orWhere(fn ($query) => $query->whereNull('due_allocations')->where('sale_id', $sale->id));
            })->get()->sum(function (SaleReturn $return) use ($sale): float {
                return $return->due_allocations === null ? (float) $return->adjusted_amount
                    : (float) collect($return->due_allocations['sales'] ?? [])->where('sale_id', $sale->id)->sum('amount');
            }), 2);
    }

    /**
     * @param  array<int, array{quantity: int, unit_cost: float, condition: string}>  $itemsToReturn
     */
    private function recordExchangeExpense(SaleReturn $return, array $itemsToReturn): void
    {
        $expenseAmount = round((float) collect($itemsToReturn)->sum(fn (array $item): float => ($item['condition'] === 'faulty' ? $item['quantity'] * $item['unit_cost'] : 0)), 2);

        if ($expenseAmount <= 0) {
            return;
        }

        Expense::query()->create([
            'category' => 'Product Return Replacement Cost',
            'amount' => $expenseAmount,
            'date' => $return->date->toDateString(),
            'payment_method' => 'stock',
            'reference' => $return->invoice_no,
            'notes' => 'Same-product exchange replacement cost for sale '.$return->sale?->invoice_no.'.',
        ]);
    }

    private function nextReturnNumber(): string
    {
        do {
            $returnNo = 'RET-'.now()->format('ymd').'-'.random_int(100, 999);
        } while (SaleReturn::query()->where('invoice_no', $returnNo)->exists());

        return $returnNo;
    }

    public function revert(SaleReturn $return): void
    {
        DB::transaction(function () use ($return): void {
            $sale = $this->lockSaleAndCustomer($return->sale_id);
            $customer = $sale->customer;
            $return = SaleReturn::query()->lockForUpdate()->findOrFail($return->id);
            $return->load('items.product');
            $return->setRelation('faultyItems', $return->faultyItems()->lockForUpdate()->get());

            $this->ensureEditable($return);

            foreach ($return->items as $item) {
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $wasFaulty = $return->faultyItems->contains('product_id', $item->product_id);
                $stockChange = $return->return_type === 'exchange'
                    ? ($wasFaulty ? $item->quantity : 0)
                    : ($wasFaulty ? 0 : -$item->quantity);

                if ($product->stock_quantity + $stockChange < 0) {
                    throw ValidationException::withMessages([
                        'returnItems' => __('The returned stock has already been used and this return cannot be reverted.'),
                    ]);
                }

                $product->increment('stock_quantity', $stockChange);
            }

            if ((float) $return->adjusted_amount > 0) {
                $this->restoreDueAdjustment($return, $sale, $customer);
            }

            $return->payments()->delete();
            $return->faultyItems()->delete();

            Expense::query()
                ->where('reference', $return->invoice_no)
                ->where('category', 'Product Return Replacement Cost')
                ->delete();

            ActivityLogger::log('pos_return_revert', "Reverted Customer Return {$return->invoice_no} for invoice {$sale->invoice_no}.");
            $return->items()->delete();
            $return->delete();
        });
    }
}
