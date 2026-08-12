<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnService
{
    /**
     * @param  array<int|string, array{name?: string|null, sku?: string|null, quantity: int|float|string, refund_price: int|float|string, max?: int|float|string, subtotal?: int|float|string}>  $returnItems
     */
    public function process(Sale $sale, array $returnItems, string $returnType, ?string $notes = null): SaleReturn
    {
        if (! in_array($returnType, ['cash_refund', 'adjust_due', 'exchange'], true)) {
            throw ValidationException::withMessages([
                'returnType' => __('Select a valid return type.'),
            ]);
        }

        $sale->loadMissing(['customer', 'items.product']);

        $itemsToReturn = $this->normaliseItems($sale, $returnItems);
        $refundTotal = (float) collect($itemsToReturn)->sum('subtotal');

        if ($refundTotal <= 0) {
            throw ValidationException::withMessages([
                'returnItems' => __('Select at least one product quantity to return.'),
            ]);
        }

        if ($returnType === 'adjust_due') {
            $customerDue = $sale->customer ? (float) $sale->customer->due_balance : 0.0;
            $maxAdjustable = max((float) $sale->due_amount, $customerDue);
            
            if ($maxAdjustable <= 0) {
                throw ValidationException::withMessages([
                    'returnType' => __('No outstanding dues exist for this customer or invoice to adjust.'),
                ]);
            }
        }

        return DB::transaction(function () use ($sale, $itemsToReturn, $refundTotal, $returnType, $notes): SaleReturn {
            $sale->refresh()->loadMissing('customer');
            $customer = $sale->customer;

            [$adjustedAmount, $refundAmount] = $this->amountsFor($sale, $refundTotal, $returnType);
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

                $this->moveReturnedStock($item, $returnType);
            }

            if ($adjustedAmount > 0) {
                $this->reduceDue($sale, $customer, $adjustedAmount);
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

            return $return->load('items.product');
        });
    }

    /**
     * @param  array<int|string, array{quantity: int|float|string, refund_price: int|float|string, subtotal?: int|float|string}>  $returnItems
     * @return array<int, array{product_id: int, quantity: int, refund_price: float, subtotal: float, unit_cost: float}>
     */
    private function normaliseItems(Sale $sale, array $returnItems): array
    {
        $items = [];

        foreach ($returnItems as $productId => $item) {
            $quantity = max(0, (int) ($item['quantity'] ?? 0));

            if ($quantity === 0) {
                continue;
            }

            $productId = (int) $productId;
            $maxReturnable = $this->maxReturnableQuantity($sale, $productId);

            if ($quantity > $maxReturnable) {
                throw ValidationException::withMessages([
                    'returnItems' => __('Return quantity exceeds the invoice balance for one or more products.'),
                ]);
            }

            $saleItem = $sale->items->firstWhere('product_id', $productId);

            if (! $saleItem) {
                throw ValidationException::withMessages([
                    'returnItems' => __('One of the selected products is not on this invoice.'),
                ]);
            }

            $refundPrice = round(max(0, (float) ($item['refund_price'] ?? $saleItem->selling_price)), 2);

            $items[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'refund_price' => $refundPrice,
                'subtotal' => round($quantity * $refundPrice, 2),
                'unit_cost' => round((float) $saleItem->cost_price, 2),
            ];
        }

        return $items;
    }

    private function maxReturnableQuantity(Sale $sale, int $productId): int
    {
        $soldQuantity = (int) $sale->items
            ->where('product_id', $productId)
            ->sum('quantity');

        $returnedQuantity = (int) SaleReturnItem::query()
            ->whereHas('returnLog', fn ($query) => $query->where('sale_id', $sale->id))
            ->where('product_id', $productId)
            ->sum('quantity');

        return max(0, $soldQuantity - $returnedQuantity);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function amountsFor(Sale $sale, float $refundTotal, string $returnType): array
    {
        if ($returnType === 'adjust_due') {
            $adjustedAmount = min($refundTotal, (float) $sale->due_amount);

            return [$adjustedAmount, max(0.00, $refundTotal - $adjustedAmount)];
        }

        if ($returnType === 'cash_refund') {
            return [0.00, $refundTotal];
        }

        return [0.00, 0.00];
    }

    /**
     * @param  array{product_id: int, quantity: int, unit_cost: float}  $item
     */
    private function moveReturnedStock(array $item, string $returnType): void
    {
        $product = Product::query()->findOrFail($item['product_id']);

        if ($returnType === 'exchange') {
            if ($product->stock_quantity < $item['quantity']) {
                throw ValidationException::withMessages([
                    'returnType' => __('Not enough same-product stock is available for this exchange. Use due adjustment or cash refund instead.'),
                ]);
            }

            $product->decrement('stock_quantity', $item['quantity']);

            return;
        }

        $product->increment('stock_quantity', $item['quantity']);
    }

    private function reduceDue(Sale $sale, ?Customer $customer, float $adjustedAmount): void
    {
        $sale->update([
            'due_amount' => round(max(0, (float) $sale->due_amount - $adjustedAmount), 2),
        ]);

        if ((float) $sale->due_amount <= 0) {
            $sale->update(['payment_status' => 'paid']);
        } elseif ((float) $sale->paid_amount > 0) {
            $sale->update(['payment_status' => 'partial']);
        } else {
            $sale->update(['payment_status' => 'due']);
        }

        if ($customer) {
            $customer->update([
                'due_balance' => round(max(0, (float) $customer->due_balance - $adjustedAmount), 2),
            ]);
        }
    }

    /**
     * @param  array<int, array{quantity: int, unit_cost: float}>  $itemsToReturn
     */
    private function recordExchangeExpense(SaleReturn $return, array $itemsToReturn): void
    {
        $expenseAmount = round((float) collect($itemsToReturn)->sum(fn (array $item): float => $item['quantity'] * $item['unit_cost']), 2);

        if ($expenseAmount <= 0) {
            return;
        }

        Expense::query()->create([
            'category' => 'Product Return Replacement Cost',
            'amount' => $expenseAmount,
            'date' => today()->toDateString(),
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
        DB::transaction(function () use ($return) {
            $return->loadMissing(['sale.customer', 'items.product', 'payments']);

            $sale = $return->sale;
            $customer = $sale?->customer;
            $returnType = $return->return_type;

            // Revert stock changes for each item
            foreach ($return->items as $item) {
                $product = $item->product;
                if ($product && $product->manage_stock) {
                    if ($returnType === 'exchange') {
                        // Exchange originally decremented stock, so we increment it back
                        $product->increment('stock_quantity', $item->quantity);
                    } else {
                        // Cash refund or adjust due originally incremented stock, so we decrement it back
                        $product->decrement('stock_quantity', $item->quantity);
                    }
                }
            }

            // Revert due balances if it was an adjust_due return
            if ($returnType === 'adjust_due' && $return->adjusted_amount > 0 && $sale) {
                $sale->update([
                    'due_amount' => round((float) $sale->due_amount + (float) $return->adjusted_amount, 2),
                ]);

                if ((float) $sale->due_amount <= 0) {
                    $sale->update(['payment_status' => 'paid']);
                } elseif ((float) $sale->paid_amount > 0) {
                    $sale->update(['payment_status' => 'partial']);
                } else {
                    $sale->update(['payment_status' => 'due']);
                }

                if ($customer) {
                    $customer->update([
                        'due_balance' => round((float) $customer->due_balance + (float) $return->adjusted_amount, 2),
                    ]);
                }
            }

            // Delete associated cash refund payments
            if ($returnType === 'cash_refund' && $return->payments()->exists()) {
                $return->payments()->delete();
            }

            // Delete associated expense for exchange returns
            if ($returnType === 'exchange') {
                Expense::query()
                    ->where('reference', $return->invoice_no)
                    ->where('category', 'Product Return Replacement Cost')
                    ->delete();
            }

            ActivityLogger::log('pos_return_revert', "Reverted Customer Return {$return->invoice_no} for invoice " . ($sale?->invoice_no ?? 'Unknown') . ".");

            // Delete the return items and the return itself
            $return->items()->delete();
            $return->delete();
        });
    }
}
