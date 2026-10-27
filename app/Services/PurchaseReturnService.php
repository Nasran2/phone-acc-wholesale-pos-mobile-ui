<?php

namespace App\Services;

use App\Models\FaultyItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    public function payExchangeDue(PurchaseReturn $return, int|float|string $amount, string $operationToken): void
    {
        abort_unless(auth()->user()?->hasPermission('add_purchase'), 403);
        Validator::make(['amount' => $amount, 'token' => $operationToken], [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'decimal:0,2'],
            'token' => ['required', 'uuid'],
        ])->validate();

        DB::transaction(function () use ($return, $amount, $operationToken): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($return->supplier_id);
            $purchase = Purchase::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($return->replacement_purchase_id);
            if ($purchase->payments()->where('reference', $operationToken)->exists()) {
                return;
            }
            $cents = $this->cents($amount);
            if ($cents > min($this->cents($purchase->due_amount), $this->cents($supplier->due_balance))) {
                throw ValidationException::withMessages(['amount' => __('Payment cannot exceed the remaining exchange due or supplier balance.')]);
            }
            $purchase->payments()->create([
                'amount' => $cents / 100, 'payment_method' => 'cash', 'date' => today(),
                'reference' => $operationToken, 'notes' => __('Cash paid against exchange :return', ['return' => $return->invoice_no]),
            ]);
            $dueCents = $this->cents($purchase->due_amount) - $cents;
            $purchase->update([
                'paid_amount' => ($this->cents($purchase->paid_amount) + $cents) / 100,
                'due_amount' => $dueCents / 100, 'payment_status' => $dueCents === 0 ? 'paid' : 'partial',
            ]);
            $supplier->decrement('due_balance', $cents / 100);
            ActivityLogger::log('purchase_return_payment', __('Paid Rs :amount against exchange :return.', ['amount' => $amount, 'return' => $return->invoice_no]));
        }, 3);
    }

    /**
     * @param  array{supplier_id: mixed, purchase_id: mixed, source: string, items: array, replacements: array, return_type: string, balance_action: string, deduction: mixed, paid_amount: mixed, notes: mixed, operation_token: string}  $input
     */
    public function process(array $input): PurchaseReturn
    {
        abort_unless(auth()->user()?->hasPermission('add_purchase'), 403);
        Validator::make($input, [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'source' => ['required', 'in:purchase,faulty'],
            'purchase_id' => ['required_if:source,purchase', 'nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.refund_price' => ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'decimal:0,2'],
            'return_type' => ['required', 'in:exchange,cash_refund,adjust_due,split'],
            'replacements' => ['array', 'max:100', 'required_if:return_type,exchange'],
            'replacements.*.product_id' => ['required', 'integer', 'distinct'],
            'replacements.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'replacements.*.cost_price' => ['required', 'numeric', 'min:0.01', 'max:9999999.99', 'decimal:0,2'],
            'balance_action' => ['required', 'in:cash_refund,adjust_due,split'],
            'deduction' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'operation_token' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($input): PurchaseReturn {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($input['supplier_id']);
            if ($existing = PurchaseReturn::query()->where('operation_token', $input['operation_token'])->first()) {
                return $existing;
            }
            $purchase = $input['source'] === 'purchase'
                ? Purchase::query()->where('supplier_id', $supplier->id)->lockForUpdate()->find($input['purchase_id'])
                : null;
            if ($input['source'] === 'purchase' && ! $purchase) {
                throw ValidationException::withMessages(['selectedPurchaseId' => __('Choose an invoice belonging to this supplier.')]);
            }

            $lines = $this->returnLines($input, $purchase);
            $replacementLines = $input['return_type'] === 'exchange' ? $input['replacements'] : [];
            $productIds = collect($lines)->pluck('product_id')->merge(collect($replacementLines)->pluck('product_id'))->unique()->sort();
            $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($productIds as $productId) {
                if (! $products->has($productId)) {
                    throw ValidationException::withMessages(['returnItems' => __('A selected product is no longer available.')]);
                }
            }
            foreach (collect($lines)->whereNull('faulty_item_id')->groupBy('product_id') as $productId => $stockLines) {
                if ($stockLines->sum('quantity') > $products[$productId]->stock_quantity) {
                    throw ValidationException::withMessages(['returnItems' => __('Not enough stock to return :product.', ['product' => $products[$productId]->name])]);
                }
            }
            $returnCents = (int) collect($lines)->sum('subtotal_cents');
            if ($purchase) {
                $previousCents = $this->cents(PurchaseReturnItem::query()->whereHas('purchaseReturn', fn ($query) => $query->where('purchase_id', $purchase->id))->sum('subtotal'));
                if ($previousCents + $returnCents > $this->cents($purchase->grand_total)) {
                    throw ValidationException::withMessages(['returnItems' => __('The total returned value cannot exceed the original invoice total after discount and tax.')]);
                }
            }
            $replacementCents = (int) collect($replacementLines)->sum(fn (array $line): int => $this->cents($line['cost_price']) * (int) $line['quantity']);
            if (max($returnCents, $replacementCents) > 99999999999) {
                throw ValidationException::withMessages(['returnItems' => __('The total value is too large.')]);
            }
            $supplierOwes = max(0, $returnCents - $replacementCents);
            $weOwe = max(0, $replacementCents - $returnCents);
            $paidCents = $this->cents($input['paid_amount']);
            if ($paidCents > $weOwe) {
                throw ValidationException::withMessages(['paidAmount' => __('Pay now cannot exceed the exchange difference.')]);
            }
            $availableDeduction = min($supplierOwes, max(0, $this->cents($supplier->due_balance)));
            $action = $input['return_type'] === 'exchange' ? $input['balance_action'] : $input['return_type'];
            $deduction = match ($action) {
                'adjust_due' => $availableDeduction,
                'split' => $this->cents($input['deduction']),
                default => 0,
            };
            if ($deduction > $availableDeduction) {
                throw ValidationException::withMessages(['deductionAmount' => __('The deduction cannot exceed the amount the supplier owes you or your existing supplier due.')]);
            }
            $return = PurchaseReturn::query()->create([
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase?->id,
                'invoice_no' => 'PRET-'.Str::upper((string) Str::ulid()),
                'operation_token' => $input['operation_token'],
                'date' => today(),
                'return_type' => $input['return_type'],
                'total_amount' => $returnCents / 100,
                'refund_amount' => ($supplierOwes - $deduction) / 100,
                'adjusted_amount' => $deduction / 100,
                'notes' => $input['notes'],
            ]);
            foreach ($lines as $line) {
                $return->items()->create([
                    'product_id' => $line['product_id'],
                    'purchase_item_id' => $line['purchase_item_id'],
                    'faulty_item_id' => $line['faulty_item_id'],
                    'quantity' => $line['quantity'],
                    'refund_price' => $line['refund_price'],
                    'subtotal' => $line['subtotal_cents'] / 100,
                ]);
                if ($line['faulty_item_id']) {
                    if ($line['quantity'] === $line['remaining']) {
                        FaultyItem::query()->whereKey($line['faulty_item_id'])->update(['status' => 'returned']);
                    }
                } else {
                    $products[$line['product_id']]->decrement('stock_quantity', $line['quantity']);
                }
            }
            if ($deduction > 0) {
                $return->update(['due_allocations' => $this->applyDeduction($supplier, $deduction, $purchase?->id)]);
            }
            if ($replacementLines !== []) {
                $creditCents = min($returnCents, $replacementCents);
                $dueCents = $weOwe - $paidCents;
                $replacement = Purchase::query()->create([
                    'supplier_id' => $supplier->id,
                    'invoice_no' => 'PEX-'.Str::upper((string) Str::ulid()),
                    'date' => today(),
                    'total_amount' => $replacementCents / 100,
                    'grand_total' => $replacementCents / 100,
                    'discount' => 0, 'tax' => 0,
                    'return_credit_amount' => $creditCents / 100,
                    'paid_amount' => ($creditCents + $paidCents) / 100,
                    'due_amount' => $dueCents / 100,
                    'payment_status' => $dueCents === 0 ? 'paid' : ($creditCents + $paidCents > 0 ? 'partial' : 'due'),
                    'notes' => __('Replacement products for :return. Settled amount includes Rs :credit in return credit.', ['return' => $return->invoice_no, 'credit' => number_format($creditCents / 100, 2)]),
                ]);
                foreach ($replacementLines as $line) {
                    $product = $products[$line['product_id']];
                    $replacement->items()->create([
                        'product_id' => $product->id, 'quantity' => $line['quantity'],
                        'cost_price' => $line['cost_price'], 'selling_price' => $product->selling_price,
                        'subtotal' => $this->cents($line['cost_price']) * (int) $line['quantity'] / 100,
                    ]);
                    $product->increment('stock_quantity', $line['quantity']);
                    $product->update(['cost_price' => $line['cost_price']]);
                }
                $return->update(['replacement_purchase_id' => $replacement->id]);
                if ($paidCents > 0) {
                    $replacement->payments()->create([
                        'amount' => $paidCents / 100, 'payment_method' => 'cash', 'date' => today(),
                        'reference' => $return->invoice_no, 'notes' => __('Cash paid for exchange difference :return', ['return' => $return->invoice_no]),
                    ]);
                }
                if ($dueCents > 0) {
                    $supplier->increment('due_balance', $dueCents / 100);
                }
            }
            if ($supplierOwes > $deduction) {
                $return->payments()->create([
                    'amount' => ($supplierOwes - $deduction) / 100, 'payment_method' => 'cash', 'date' => today(),
                    'reference' => $return->invoice_no, 'notes' => __('Cash received from supplier for return :return', ['return' => $return->invoice_no]),
                ]);
            }
            ActivityLogger::log('purchase_return', __('Processed supplier return :return.', ['return' => $return->invoice_no]));

            return $return;
        }, 3);
    }

    /**
     * @param  array{source: string, items: array}  $input
     * @return array<int, array{product_id: int, purchase_item_id: ?int, faulty_item_id: ?int, quantity: int, remaining: int, refund_price: float, subtotal_cents: int}>
     */
    private function returnLines(array $input, ?Purchase $purchase): array
    {
        $records = $input['source'] === 'purchase'
            ? $purchase->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : FaultyItem::query()->with('product')->whereIn('id', collect($input['items'])->pluck('id'))->where('status', 'pending')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $foreignKey = $purchase ? 'purchase_item_id' : 'faulty_item_id';
        if ($purchase) {
            $purchase->setRelation('items', $records->values());
        }
        $invoiceValues = $purchase ? $this->invoiceLineValues($purchase) : [];
        $returned = PurchaseReturnItem::query()->whereIn($foreignKey, $records->keys())
            ->selectRaw($foreignKey.', SUM(quantity) as returned_quantity')->groupBy($foreignKey)->pluck('returned_quantity', $foreignKey);
        $lines = [];
        foreach ($input['items'] as $item) {
            $record = $records->get($item['id']);
            $errorKey = $purchase ? 'returnItems' : 'selectedFaultyItems';
            if (! $record) {
                throw ValidationException::withMessages([$errorKey => __('Some selected items have already changed or been returned. Refresh and try again.')]);
            }
            $remaining = (int) $record->quantity - (int) ($returned[$record->id] ?? 0);
            if ((int) $item['quantity'] > $remaining) {
                throw ValidationException::withMessages([$errorKey => __('Return quantity exceeds the remaining quantity.')]);
            }
            $subtotalCents = $this->cents($item['refund_price']) * (int) $item['quantity'];
            if ($purchase) {
                $lineValue = $invoiceValues[$record->id];
                $maxPrice = round($lineValue / $record->quantity / 100, 2);
                if ($this->cents($item['refund_price']) > $this->cents($maxPrice)) {
                    throw ValidationException::withMessages(['returnItems' => __('Return price cannot exceed the original price after invoice discount and tax.')]);
                }
                if ($this->cents($item['refund_price']) === $this->cents($maxPrice)) {
                    $previousQuantity = (int) ($returned[$record->id] ?? 0);
                    $subtotalCents = (int) round($lineValue * ($previousQuantity + (int) $item['quantity']) / $record->quantity)
                        - (int) round($lineValue * $previousQuantity / $record->quantity);
                }
            }
            $lines[] = [
                'product_id' => $record->product_id,
                'purchase_item_id' => $purchase ? $record->id : null,
                'faulty_item_id' => $purchase ? null : $record->id,
                'quantity' => (int) $item['quantity'], 'remaining' => $remaining,
                'refund_price' => $this->cents($item['refund_price']) / 100,
                'subtotal_cents' => $subtotalCents,
            ];
        }

        return $lines;
    }

    /** @return array<int, int> */
    public function invoiceLineValues(Purchase $purchase): array
    {
        $values = [];
        $subtotal = 0;
        $allocated = 0;
        $invoiceTotal = $this->cents($purchase->grand_total);
        $originalSubtotal = max(1, $this->cents($purchase->total_amount));
        foreach ($purchase->items->sortBy('id') as $item) {
            $subtotal += $this->cents($item->subtotal);
            $nextAllocated = (int) round($subtotal * $invoiceTotal / $originalSubtotal);
            $values[$item->id] = $nextAllocated - $allocated;
            $allocated = $nextAllocated;
        }

        return $values;
    }

    /** @return array<int, float> */
    private function applyDeduction(Supplier $supplier, int $deduction, ?int $sourcePurchaseId): array
    {
        $remaining = $deduction;
        $allocations = [];
        $purchases = Purchase::query()->where('supplier_id', $supplier->id)->where('due_amount', '>', 0)
            ->orderBy('id')->lockForUpdate()->get()->sortBy(fn (Purchase $purchase): int => $purchase->id === $sourcePurchaseId ? 0 : 1);
        foreach ($purchases as $purchase) {
            $amount = min($remaining, $this->cents($purchase->due_amount));
            if ($amount <= 0) {
                break;
            }
            $dueCents = $this->cents($purchase->due_amount) - $amount;
            $pending = $purchase->payments()->pendingCheque()->exists();
            $purchase->update([
                'due_amount' => $dueCents / 100,
                'payment_status' => $pending ? 'cheque_pending' : ($dueCents === 0 ? 'paid' : 'partial'),
            ]);
            $allocations[$purchase->id] = $amount / 100;
            $remaining -= $amount;
        }
        $supplier->decrement('due_balance', $deduction / 100);

        return $allocations;
    }

    private function cents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
