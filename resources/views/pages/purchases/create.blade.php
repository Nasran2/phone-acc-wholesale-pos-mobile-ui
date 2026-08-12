<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\ActivityLogger;
use App\Services\TextItSmsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new #[Title('Record Wholesale Purchase')] class extends Component
{
    public ?int $editingPurchaseId = null;
    public string $invoice_no = '';
    public string $date = '';
    public ?int $supplier_id = null;

    // Cart and item search state
    public string $productSearch = '';
    public array $cart = [];

    // Quick product create state
    public bool $productCreateOpen = false;
    public string $newProductName = '';
    public string $newProductSku = '';
    public ?string $newProductBarcode = null;
    public ?int $newProductCategoryId = null;
    public ?int $newProductBrandId = null;
    public ?int $newProductUnitId = null;
    public ?string $newProductCompatibleModels = null;
    public $newProductCostPrice = 0.0;
    public $newProductSellingPrice = 0.0;
    public $newProductWholesalePrice = null;
    public int $newProductMinimumStock = 0;

    // Payment and discounts state
    public $discount = 0.00;
    public $paid_amount = 0.00;
    public string $payment_method = 'cash';
    public string $payment_reference = '';
    public string $cheque_type = 'party';
    public string $cheque_bank = '';
    public string $cheque_no = '';
    public string $cheque_date = '';
    public string $party_cheque_search = '';
    public ?int $party_cheque_payment_id = null;
    public array $paymentRows = [];
    public string $notes = '';

    public function mount(?Purchase $purchase = null): void
    {
        if ($purchase?->exists) {
            $this->loadPurchaseForEditing($purchase);

            return;
        }

        $this->date = date('Y-m-d');
        $this->invoice_no = 'PUR-' . date('ymd') . '-' . rand(100, 999);
        $this->paymentRows = [$this->blankPaymentRow()];

        if ($productId = request('product_id')) {
            if (Product::query()->find($productId)) {
                $this->selectProduct((int) $productId);
            }
        }
    }

    public function selectProduct(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);

        // Check if already in cart, increment quantity
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $product->id) {
                $this->cart[$index]['quantity']++;
                $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['cost_price'];
                $this->productSearch = '';
                $this->syncAutoPaidAmount();
                return;
            }
        }

        // Add new row to cart
        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'cost_price' => (float) $product->cost_price,
            'selling_price' => (float) $product->selling_price,
            'subtotal' => (float) $product->cost_price,
        ];

        $this->productSearch = '';
        $this->syncAutoPaidAmount();
    }

    public function updateCartRow(int $index, string $field, $value): void
    {
        if (isset($this->cart[$index])) {
            if ($field === 'quantity') {
                $this->cart[$index]['quantity'] = max(1, (int) $value);
            } elseif ($field === 'cost_price') {
                $this->cart[$index]['cost_price'] = max(0.00, (float) $value);
            } elseif ($field === 'selling_price') {
                $this->cart[$index]['selling_price'] = max(0.00, (float) $value);
            }

            // Recalculate row subtotal
            $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['cost_price'];
            $this->syncAutoPaidAmount();
        }
    }

    public function removeCartRow(int $index): void
    {
        if (isset($this->cart[$index])) {
            unset($this->cart[$index]);
            $this->cart = array_values($this->cart);
            $this->syncAutoPaidAmount();
        }
    }

    public function openProductModal(): void
    {
        $this->productCreateOpen = true;
    }

    public function closeProductModal(): void
    {
        $this->resetNewProductForm();
        $this->productCreateOpen = false;
    }

    public function saveNewProduct(): void
    {
        $this->prepareNewProductFields();

        $this->validate([
            'newProductName' => 'required|string|max:180',
            'newProductCategoryId' => 'nullable|integer|exists:categories,id',
            'newProductBrandId' => 'nullable|integer|exists:brands,id',
            'newProductUnitId' => 'nullable|integer|exists:units,id',
            'newProductSku' => ['required', 'string', 'max:64', Rule::unique(Product::class, 'sku')],
            'newProductBarcode' => ['nullable', 'string', 'max:64', Rule::unique(Product::class, 'barcode')],
            'newProductCompatibleModels' => 'nullable|string|max:255',
            'newProductCostPrice' => 'required|numeric|min:0',
            'newProductSellingPrice' => 'required|numeric|min:0',
            'newProductWholesalePrice' => 'nullable|numeric|min:0',
            'newProductMinimumStock' => 'nullable|integer|min:0',
        ]);

        $product = Product::query()->create([
            'category_id' => $this->newProductCategoryId,
            'brand_id' => $this->newProductBrandId,
            'unit_id' => $this->newProductUnitId,
            'name' => $this->newProductName,
            'sku' => $this->newProductSku,
            'barcode' => $this->newProductBarcode ?: null,
            'compatible_models' => $this->newProductCompatibleModels ?: null,
            'cost_price' => (float) $this->newProductCostPrice,
            'selling_price' => (float) $this->newProductSellingPrice,
            'wholesale_price' => ($this->newProductWholesalePrice !== null && $this->newProductWholesalePrice !== '')
                ? (float) $this->newProductWholesalePrice
                : null,
            'stock_quantity' => 0,
            'minimum_stock' => (int) $this->newProductMinimumStock,
            'warranty_enabled' => false,
            'warranty_period_days' => null,
            'is_active' => true,
        ]);

        $this->selectProduct($product->id);
        Flux::toast(variant: 'success', text: __('Product added.'));

        $this->resetNewProductForm();
        $this->productCreateOpen = false;
    }

    private function prepareNewProductFields(): void
    {
        $this->newProductSku = trim($this->newProductSku);

        if ($this->newProductSku === '') {
            $this->newProductSku = strtoupper(uniqid('SKU-'));
        }

        if ($this->newProductBarcode === null || trim((string) $this->newProductBarcode) === '') {
            $this->newProductBarcode = $this->newProductSku;
        }
    }

    private function resetNewProductForm(): void
    {
        $this->reset(
            'newProductName',
            'newProductSku',
            'newProductBarcode',
            'newProductCategoryId',
            'newProductBrandId',
            'newProductUnitId',
            'newProductCompatibleModels',
            'newProductCostPrice',
            'newProductSellingPrice',
            'newProductWholesalePrice',
            'newProductMinimumStock'
        );
    }

    public function updatedPaymentMethod(): void
    {
        $this->syncLegacyPaymentToFirstRow();
        $this->syncAutoPaidAmount();
    }

    public function updatedPaidAmount(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedChequeType(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedDiscount(): void
    {
        $this->syncAutoPaidAmount();
    }

    private function syncAutoPaidAmount(): void
    {
        if (in_array($this->payment_method, ['cash', 'bank_transfer'], true)) {
            $this->paid_amount = max(0.00, (float) $this->cartTotal);
            $this->syncLegacyPaymentToFirstRow();
        }
    }

    public function addPaymentRow(string $method = 'cheque'): void
    {
        $this->paymentRows[] = $this->blankPaymentRow($method);
    }

    public function removePaymentRow(int $index): void
    {
        if (! isset($this->paymentRows[$index])) {
            return;
        }

        unset($this->paymentRows[$index]);
        $this->paymentRows = array_values($this->paymentRows);

        if (count($this->paymentRows) === 0) {
            $this->paymentRows = [$this->blankPaymentRow()];
        }

        $this->syncFirstRowToLegacyPayment();
    }

    public function updatePaymentRow(int $index, string $field, mixed $value): void
    {
        if (! isset($this->paymentRows[$index])) {
            return;
        }

        if ($field === 'amount') {
            $this->paymentRows[$index]['amount'] = max(0, (float) $value);
        } elseif ($field === 'method') {
            $this->paymentRows[$index]['method'] = in_array($value, ['cash', 'bank_transfer', 'cheque'], true) ? $value : 'cash';
        } elseif ($field === 'cheque_type') {
            $this->paymentRows[$index]['cheque_type'] = in_array($value, ['own', 'party'], true) ? $value : 'party';
            $this->paymentRows[$index]['party_cheque_payment_id'] = null;
        } elseif (in_array($field, ['cheque_no', 'cheque_bank', 'cheque_date', 'party_cheque_search'], true)) {
            $this->paymentRows[$index][$field] = (string) $value;
        }

        $this->syncFirstRowToLegacyPayment();
    }

    public function updatedPaymentRows(mixed $value, string $key): void
    {
        if (! preg_match('/^(\d+)\.(cheque_no|method|cheque_type|party_cheque_payment_id)$/', $key, $matches)) {
            return;
        }

        $this->validatePaymentRowChequeNumber((int) $matches[1]);
    }

    public function selectPaymentRowPartyCheque(int $index, int $paymentId): void
    {
        if (! isset($this->paymentRows[$index])) {
            return;
        }

        $payment = $this->findAvailablePartyCheque($paymentId);

        $this->paymentRows[$index]['method'] = 'cheque';
        $this->paymentRows[$index]['cheque_type'] = 'party';
        $this->paymentRows[$index]['party_cheque_payment_id'] = $payment->id;
        $this->paymentRows[$index]['party_cheque_search'] = $payment->cheque_no ?: (string) $payment->reference;
        $this->paymentRows[$index]['cheque_no'] = $payment->cheque_no ?: (string) $payment->reference;
        $this->paymentRows[$index]['cheque_bank'] = (string) $payment->cheque_bank;
        $this->paymentRows[$index]['cheque_date'] = $payment->cheque_date?->toDateString() ?? '';
        $this->paymentRows[$index]['amount'] = min((float) $payment->amount, max(0, $this->cartTotal - $this->paymentRowsTotalExcluding($index)));

        $this->syncFirstRowToLegacyPayment();
    }

    public function clearPaymentRowPartyCheque(int $index): void
    {
        if (! isset($this->paymentRows[$index])) {
            return;
        }

        $this->paymentRows[$index]['party_cheque_payment_id'] = null;
        $this->paymentRows[$index]['party_cheque_search'] = '';
        $this->paymentRows[$index]['cheque_no'] = '';
        $this->paymentRows[$index]['cheque_bank'] = '';
        $this->paymentRows[$index]['cheque_date'] = '';

        $this->syncFirstRowToLegacyPayment();
    }

    public function savePurchase(): void
    {
        $rules = [
            'invoice_no' => ['required', 'string', Rule::unique(Purchase::class, 'invoice_no')->ignore($this->editingPurchaseId)],
            'date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'cart' => 'required|array|min:1',
            'cart.*.product_id' => 'required|exists:products,id',
            'cart.*.quantity' => 'required|integer|min:1',
            'cart.*.cost_price' => 'required|numeric|min:0',
            'cart.*.selling_price' => 'required|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'paymentRows' => 'required|array|min:1',
            'paymentRows.*.amount' => 'required|numeric|min:0',
            'paymentRows.*.method' => 'required|in:cash,bank_transfer,cheque',
            'paymentRows.*.cheque_type' => 'nullable|in:own,party',
            'paymentRows.*.cheque_no' => 'nullable|string|max:100',
            'paymentRows.*.cheque_bank' => 'nullable|string|max:100',
            'paymentRows.*.cheque_date' => 'nullable|date',
            'paymentRows.*.party_cheque_payment_id' => 'nullable|exists:payments,id',
        ];

        $this->validate($rules);

        $subtotal = $this->cartSubtotal;
        $grandTotal = $subtotal - (float) $this->discount;
        $paymentRows = $this->normalisedPaymentRows();

        if ($this->paymentRowsTotal($paymentRows) > $grandTotal) {
            $this->addError('paymentRows', __('Payment rows cannot exceed the purchase grand total.'));

            return;
        }

        foreach ($paymentRows as $index => $paymentRow) {
            if ($paymentRow['method'] !== 'cheque') {
                continue;
            }

            if ($paymentRow['source_payment_id'] && $paymentRow['amount'] > $paymentRow['source_amount']) {
                $this->addError("paymentRows.{$index}.amount", __('Party cheque amount cannot exceed the selected customer cheque amount.'));

                return;
            }

            if ($paymentRow['cheque_type'] === 'own' && blank($paymentRow['cheque_no'])) {
                $this->addError("paymentRows.{$index}.cheque_no", __('Own cheque number is required.'));

                return;
            }

            if (blank($paymentRow['cheque_date'])) {
                $this->addError("paymentRows.{$index}.cheque_date", __('Cheque date is required.'));

                return;
            }

            if ($paymentRow['cheque_type'] === 'party' && ! $paymentRow['source_payment_id'] && blank($paymentRow['cheque_no'])) {
                $this->addError("paymentRows.{$index}.cheque_no", __('Party cheque number is required when it is not selected from saved customer cheques.'));

                return;
            }

            if (! $this->ensureUniqueChequeNumber($index, $paymentRow, $paymentRows)) {
                return;
            }
        }

        $capturedPaidAmount = $this->cashPaymentRowsTotal($paymentRows);
        $heldChequeAmount = $this->chequePaymentRowsTotal($paymentRows);
        $dueAmount = max(0.00, $grandTotal - $capturedPaidAmount - $heldChequeAmount);

        $paymentStatus = 'due';
        if ($heldChequeAmount > 0) {
            $paymentStatus = $dueAmount > 0 ? 'partial' : 'cheque_pending';
        } elseif ($capturedPaidAmount >= $grandTotal) {
            $paymentStatus = 'paid';
        } elseif ($capturedPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        DB::transaction(function () use ($subtotal, $grandTotal, $capturedPaidAmount, $dueAmount, $paymentStatus, $paymentRows): void {
            $purchase = $this->editingPurchaseId
                ? Purchase::query()->with(['items', 'payments'])->findOrFail($this->editingPurchaseId)
                : new Purchase();

            $oldSupplierId = $purchase->supplier_id;
            $oldDueAmount = (float) ($purchase->due_amount ?? 0);
            $oldItemQuantities = $purchase->exists
                ? $purchase->items->groupBy('product_id')->map(fn ($items): int => (int) $items->sum('quantity'))->all()
                : [];

            $purchase->fill([
                'supplier_id' => $this->supplier_id,
                'invoice_no' => $this->invoice_no,
                'date' => $this->date,
                'total_amount' => $subtotal,
                'discount' => (float) $this->discount,
                'tax' => 0.0,
                'grand_total' => $grandTotal,
                'paid_amount' => $capturedPaidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'notes' => $this->notes,
            ])->save();

            if ($purchase->wasRecentlyCreated === false) {
                $purchase->items()->delete();
                $purchase->payments()->delete();
            }

            $newItemQuantities = [];
            foreach ($this->cart as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                $productId = (int) $item['product_id'];
                $newItemQuantities[$productId] = ($newItemQuantities[$productId] ?? 0) + (int) $item['quantity'];
            }

            foreach ($newItemQuantities as $productId => $newQuantity) {
                $oldQuantity = (int) ($oldItemQuantities[$productId] ?? 0);
                $this->adjustProductStock($productId, $newQuantity - $oldQuantity);
            }

            foreach (array_diff_key($oldItemQuantities, $newItemQuantities) as $productId => $oldQuantity) {
                $this->adjustProductStock((int) $productId, -((int) $oldQuantity));
            }

            foreach ($this->cart as $item) {
                Product::query()
                    ->whereKey($item['product_id'])
                    ->update([
                        'cost_price' => $item['cost_price'],
                        'selling_price' => $item['selling_price'],
                    ]);
            }

            foreach ($paymentRows as $paymentRow) {
                if ($paymentRow['amount'] <= 0) {
                    continue;
                }

                $isChequePayment = $paymentRow['method'] === 'cheque';

                $purchase->payments()->create([
                    'amount' => $paymentRow['amount'],
                    'payment_method' => $paymentRow['method'],
                    'date' => $this->date,
                    'reference' => $isChequePayment ? $paymentRow['cheque_no'] : $paymentRow['reference'],
                    'cheque_bank' => $isChequePayment ? $paymentRow['cheque_bank'] : null,
                    'cheque_no' => $isChequePayment ? $paymentRow['cheque_no'] : null,
                    'cheque_date' => $isChequePayment ? $paymentRow['cheque_date'] : null,
                    'cheque_status' => $isChequePayment ? $paymentRow['cheque_status'] : null,
                    'cheque_type' => $isChequePayment ? $paymentRow['cheque_type'] : null,
                    'source_payment_id' => $isChequePayment && $paymentRow['cheque_type'] === 'party' ? $paymentRow['source_payment_id'] : null,
                    'party_customer_id' => $isChequePayment && $paymentRow['cheque_type'] === 'party' ? $paymentRow['party_customer_id'] : null,
                    'notes' => $isChequePayment ? 'Supplier cheque payment on hold until cleared.' : 'Restock purchase invoice payments.',
                ]);
            }

            if ($oldSupplierId && $oldSupplierId !== $purchase->supplier_id && $oldDueAmount > 0) {
                $oldSupplier = Supplier::query()->find($oldSupplierId);
                if ($oldSupplier) {
                    $oldSupplier->update([
                        'due_balance' => round(max(0, (float) $oldSupplier->due_balance - $oldDueAmount), 2),
                    ]);
                }

                $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
                $supplier->update([
                    'due_balance' => round(max(0, (float) $supplier->due_balance + $dueAmount), 2),
                ]);
            } else {
                $dueDelta = round($dueAmount - $oldDueAmount, 2);
                if ($dueDelta !== 0.0) {
                    $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
                    $supplier->update([
                        'due_balance' => round(max(0, (float) $supplier->due_balance + $dueDelta), 2),
                    ]);
                }
            }
        });

        ActivityLogger::log(
            $this->editingPurchaseId ? 'purchase_update' : 'purchase_create',
            ($this->editingPurchaseId ? 'Updated' : 'Registered') . " restock invoice {$this->invoice_no}. Total: Rs {$grandTotal}, Supplier Dues: Rs {$dueAmount}."
        );
        Flux::toast(variant: 'success', text: $this->editingPurchaseId ? __('Purchase restock successfully updated.') : __('Purchase restock successfully recorded.'));

        $this->redirectRoute('purchases.index', navigate: true);
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->orderBy('name')->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function brands()
    {
        return Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function units()
    {
        return Unit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function products()
    {
        if (empty($this->productSearch)) return [];

        return Product::query()
            ->where(function($q) {
                $q->where('name', 'like', '%' . $this->productSearch . '%')
                  ->orWhere('sku', 'like', '%' . $this->productSearch . '%')
                  ->orWhere('barcode', 'like', '%' . $this->productSearch . '%')
                  ->orWhere('compatible_models', 'like', '%' . $this->productSearch . '%');
            })
            ->limit(5)
            ->get();
    }

    public function partyChequesForRow(int $index)
    {
        $search = (string) ($this->paymentRows[$index]['party_cheque_search'] ?? '');

        if ($search === '') {
            return [];
        }

        return $this->availablePartyChequeQuery($search)
            ->with('paymentable.customer')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function partyCheques()
    {
        if (empty($this->party_cheque_search)) {
            return [];
        }

        return $this->availablePartyChequeQuery($this->party_cheque_search)
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function selectedPartyCheque()
    {
        if (! $this->party_cheque_payment_id) {
            return null;
        }

        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->with('paymentable.customer')
            ->find($this->party_cheque_payment_id);
    }

    #[Computed]
    public function isEditing(): bool
    {
        return $this->editingPurchaseId !== null;
    }

    public function selectPartyCheque(int $paymentId): void
    {
        $payment = $this->findAvailablePartyCheque($paymentId);

        $this->party_cheque_payment_id = $payment->id;
        $this->party_cheque_search = $payment->cheque_no ?: (string) $payment->reference;
        $this->paid_amount = min((float) $payment->amount, $this->cartTotal);
        $this->syncLegacyPaymentToFirstRow();
    }

    #[Computed]
    public function cartSubtotal()
    {
        return array_reduce($this->cart, fn($carry, $item) => $carry + $item['subtotal'], 0.00);
    }

    #[Computed]
    public function cartTotal()
    {
        return $this->cartSubtotal - (float) $this->discount;
    }

    #[Computed]
    public function paymentRowsTotalAmount()
    {
        return $this->paymentRowsTotal($this->normalisedPaymentRows(validateSourceCheques: false));
    }

    #[Computed]
    public function outstandingDue()
    {
        return max(0.00, $this->cartTotal - $this->paymentRowsTotalAmount);
    }

    private function blankPaymentRow(string $method = 'cash'): array
    {
        return [
            'amount' => 0.00,
            'method' => $method,
            'reference' => '',
            'cheque_type' => 'party',
            'cheque_no' => '',
            'cheque_bank' => '',
            'cheque_date' => '',
            'cheque_status' => 'pending',
            'party_cheque_search' => '',
            'party_cheque_payment_id' => null,
        ];
    }

    private function syncLegacyPaymentToFirstRow(): void
    {
        if (! isset($this->paymentRows[0])) {
            $this->paymentRows[0] = $this->blankPaymentRow($this->payment_method);
        }

        $this->paymentRows[0]['amount'] = (float) $this->paid_amount;
        $this->paymentRows[0]['method'] = $this->payment_method;
        $this->paymentRows[0]['reference'] = $this->payment_reference;
        $this->paymentRows[0]['cheque_type'] = $this->cheque_type;
        $this->paymentRows[0]['cheque_no'] = $this->cheque_no;
        $this->paymentRows[0]['cheque_bank'] = $this->cheque_bank;
        $this->paymentRows[0]['cheque_date'] = $this->cheque_date;
        $this->paymentRows[0]['cheque_status'] = $this->paymentRows[0]['cheque_status'] ?? 'pending';
        $this->paymentRows[0]['party_cheque_search'] = $this->party_cheque_search;
        $this->paymentRows[0]['party_cheque_payment_id'] = $this->party_cheque_payment_id;
    }

    private function syncFirstRowToLegacyPayment(): void
    {
        $firstRow = $this->paymentRows[0] ?? $this->blankPaymentRow();

        $this->paid_amount = (float) ($firstRow['amount'] ?? 0);
        $this->payment_method = (string) ($firstRow['method'] ?? 'cash');
        $this->payment_reference = (string) ($firstRow['reference'] ?? '');
        $this->cheque_type = (string) ($firstRow['cheque_type'] ?? 'party');
        $this->cheque_no = (string) ($firstRow['cheque_no'] ?? '');
        $this->cheque_bank = (string) ($firstRow['cheque_bank'] ?? '');
        $this->cheque_date = (string) ($firstRow['cheque_date'] ?? '');
        $this->party_cheque_search = (string) ($firstRow['party_cheque_search'] ?? '');
        $this->party_cheque_payment_id = $firstRow['party_cheque_payment_id'] ? (int) $firstRow['party_cheque_payment_id'] : null;
    }

    private function paymentRowsTotalExcluding(int $excludedIndex): float
    {
        return $this->paymentRowsTotal(
            collect($this->normalisedPaymentRows(validateSourceCheques: false))
                ->except($excludedIndex)
                ->all()
        );
    }

    private function normalisedPaymentRows(bool $validateSourceCheques = true): array
    {
        return collect($this->paymentRows)
            ->map(function (array $row) use ($validateSourceCheques): array {
                $method = in_array($row['method'] ?? 'cash', ['cash', 'bank_transfer', 'cheque'], true) ? $row['method'] : 'cash';
                $chequeType = in_array($row['cheque_type'] ?? 'party', ['own', 'party'], true) ? $row['cheque_type'] : 'party';
                $chequeStatus = in_array($row['cheque_status'] ?? 'pending', ['pending', 'passed'], true) ? $row['cheque_status'] ?? 'pending' : 'pending';
                $sourcePayment = null;

                if ($validateSourceCheques && $method === 'cheque' && $chequeType === 'party' && filled($row['party_cheque_payment_id'] ?? null)) {
                    $sourcePayment = $this->findAvailablePartyCheque((int) $row['party_cheque_payment_id']);
                }

                return [
                    'amount' => round(max(0, (float) ($row['amount'] ?? 0)), 2),
                    'method' => $method,
                    'reference' => (string) ($row['reference'] ?? ''),
                    'cheque_type' => $chequeType,
                    'cheque_status' => $chequeStatus,
                    'cheque_no' => $method === 'cheque'
                        ? (string) ($sourcePayment?->cheque_no ?: ($row['cheque_no'] ?? $row['party_cheque_search'] ?? ''))
                        : '',
                    'cheque_bank' => $method === 'cheque'
                        ? (string) ($sourcePayment?->cheque_bank ?: ($row['cheque_bank'] ?? ''))
                        : '',
                    'cheque_date' => $method === 'cheque'
                        ? (string) ($sourcePayment?->cheque_date?->toDateString() ?: ($row['cheque_date'] ?? ''))
                        : null,
                    'source_payment_id' => $sourcePayment?->id,
                    'party_customer_id' => $sourcePayment?->paymentable?->customer_id,
                    'source_amount' => (float) ($sourcePayment?->amount ?? 0),
                ];
            })
            ->filter(fn (array $row): bool => $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function paymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)->sum('amount'), 2);
    }

    private function cashPaymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)
            ->filter(fn (array $row): bool => in_array($row['method'], ['cash', 'bank_transfer'], true)
                || ($row['method'] === 'cheque' && ($row['cheque_status'] ?? 'pending') === 'passed'))
            ->sum('amount'), 2);
    }

    private function chequePaymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)
            ->where('method', 'cheque')
            ->filter(fn (array $row): bool => ($row['cheque_status'] ?? 'pending') === 'pending')
            ->sum('amount'), 2);
    }

    private function findAvailablePartyCheque(int $paymentId): Payment
    {
        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDoesntHave('issuedPayments', fn ($query) => $query
                ->where('cheque_status', 'pending')
                ->when($this->editingPurchaseId, fn ($query) => $query
                    ->where(function ($query): void {
                        $query->where('paymentable_type', '!=', Purchase::class)
                            ->orWhere('paymentable_id', '!=', $this->editingPurchaseId);
                    })))
            ->with('paymentable.customer')
            ->findOrFail($paymentId);
    }

    private function ensureUniqueChequeNumber(int $index, array $paymentRow, array $paymentRows): bool
    {
        if ($paymentRow['method'] !== 'cheque' || $paymentRow['source_payment_id']) {
            return true;
        }

        $chequeNo = trim((string) $paymentRow['cheque_no']);
        if ($chequeNo === '') {
            return true;
        }

        $duplicateRowNumber = collect($paymentRows)
            ->take($index)
            ->filter(fn (array $row): bool => $row['method'] === 'cheque'
                && ! $row['source_payment_id']
                && strcasecmp(trim((string) $row['cheque_no']), $chequeNo) === 0)
            ->keys()
            ->first();

        if ($duplicateRowNumber !== null) {
            $this->addError(
                "paymentRows.{$index}.cheque_no",
                __('This cheque number is already used in payment #:number.', ['number' => $duplicateRowNumber + 1])
            );

            return false;
        }

        $duplicatePayment = $this->findDuplicateChequePayment($chequeNo);
        if (! $duplicatePayment) {
            return true;
        }

        $this->addError(
            "paymentRows.{$index}.cheque_no",
            __('This cheque number already exists for :owner.', ['owner' => $this->chequeOwnerDescription($duplicatePayment)])
        );

        return false;
    }

    private function validatePaymentRowChequeNumber(int $index): bool
    {
        $errorKey = "paymentRows.{$index}.cheque_no";
        $this->resetErrorBag($errorKey);

        if (! isset($this->paymentRows[$index])) {
            return true;
        }

        $paymentRow = $this->paymentRows[$index];
        if (($paymentRow['method'] ?? 'cash') !== 'cheque' || filled($paymentRow['party_cheque_payment_id'] ?? null)) {
            return true;
        }

        $chequeNo = trim((string) ($paymentRow['cheque_no'] ?? ''));
        if ($chequeNo === '') {
            return true;
        }

        $duplicateRowNumber = collect($this->paymentRows)
            ->except($index)
            ->filter(fn (array $row): bool => ($row['method'] ?? 'cash') === 'cheque'
                && blank($row['party_cheque_payment_id'] ?? null)
                && strcasecmp(trim((string) ($row['cheque_no'] ?? '')), $chequeNo) === 0)
            ->keys()
            ->first();

        if ($duplicateRowNumber !== null) {
            $this->addError(
                $errorKey,
                __('This cheque number is already used in payment #:number.', ['number' => $duplicateRowNumber + 1])
            );

            return false;
        }

        $duplicatePayment = $this->findDuplicateChequePayment($chequeNo);
        if (! $duplicatePayment) {
            return true;
        }

        $this->addError(
            $errorKey,
            __('This cheque number already exists for :owner.', ['owner' => $this->chequeOwnerDescription($duplicatePayment)])
        );

        return false;
    }

    private function findDuplicateChequePayment(string $chequeNo): ?Payment
    {
        return Payment::query()
            ->where('payment_method', 'cheque')
            ->whereNotNull('cheque_no')
            ->whereRaw('LOWER(cheque_no) = ?', [strtolower($chequeNo)])
            ->when($this->editingPurchaseId, fn ($query) => $query
                ->where(function ($query): void {
                    $query->where('paymentable_type', '!=', Purchase::class)
                        ->orWhere('paymentable_id', '!=', $this->editingPurchaseId);
                }))
            ->with('paymentable')
            ->first();
    }

    private function chequeOwnerDescription(Payment $payment): string
    {
        $paymentable = $payment->paymentable;

        if ($paymentable instanceof Sale) {
            $paymentable->loadMissing('customer');

            return __('customer :name', ['name' => $paymentable->customer?->name ?? __('Unknown Customer')]);
        }

        if ($paymentable instanceof Customer) {
            return __('customer :name', ['name' => $paymentable->name]);
        }

        if ($paymentable instanceof Purchase) {
            $paymentable->loadMissing('supplier');

            return __('supplier :name', ['name' => $paymentable->supplier?->name ?? __('Unknown Supplier')]);
        }

        if ($paymentable instanceof Supplier) {
            return __('supplier :name', ['name' => $paymentable->name]);
        }

        return __('another payment record');
    }

    private function availablePartyChequeQuery(string $search)
    {
        return Payment::query()
            ->pendingCheque()
            ->whereIn('paymentable_type', [Sale::class, Customer::class])
            ->whereDoesntHave('issuedPayments', fn ($query) => $query
                ->where('cheque_status', 'pending')
                ->when($this->editingPurchaseId, fn ($query) => $query
                    ->where(function ($query): void {
                        $query->where('paymentable_type', '!=', Purchase::class)
                            ->orWhere('paymentable_id', '!=', $this->editingPurchaseId);
                    })))
            ->where(function ($query) use ($search): void {
                $query->where('cheque_no', 'like', '%' . $search . '%')
                    ->orWhere('reference', 'like', '%' . $search . '%')
                    ->orWhereHasMorph('paymentable', [Sale::class], fn ($query) => $query->where('invoice_no', 'like', '%' . $search . '%'))
                    ->orWhereHasMorph('paymentable', [Customer::class], fn ($query) => $query->where('name', 'like', '%' . $search . '%'));
            })
            ->with('paymentable.customer');
    }

    private function loadPurchaseForEditing(Purchase $purchase): void
    {
        $purchase->load(['items.product', 'payments.sourcePayment.paymentable.customer']);

        $this->editingPurchaseId = $purchase->id;
        $this->invoice_no = $purchase->invoice_no;
        $this->date = $purchase->date->toDateString();
        $this->supplier_id = $purchase->supplier_id;
        $this->discount = (float) $purchase->discount;
        $this->notes = (string) $purchase->notes;
        $this->cart = $purchase->items
            ->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'name' => $item->product?->name ?? __('Deleted Product'),
                'sku' => $item->product?->sku ?? '',
                'quantity' => (int) $item->quantity,
                'cost_price' => (float) $item->cost_price,
                'selling_price' => (float) $item->selling_price,
                'subtotal' => (float) $item->subtotal,
            ])
            ->values()
            ->all();
        $this->paymentRows = $purchase->payments
            ->map(fn (Payment $payment): array => $this->paymentToRow($payment))
            ->values()
            ->all();

        if ($this->paymentRows === []) {
            $this->paymentRows = [$this->blankPaymentRow()];
        }

        $this->syncFirstRowToLegacyPayment();
    }

    private function paymentToRow(Payment $payment): array
    {
        $sourcePayment = $payment->sourcePayment;

        return [
            'amount' => (float) $payment->amount,
            'method' => $payment->payment_method,
            'reference' => (string) $payment->reference,
            'cheque_type' => $payment->cheque_type ?: 'party',
            'cheque_no' => (string) ($payment->cheque_no ?: $payment->reference),
            'cheque_bank' => (string) $payment->cheque_bank,
            'cheque_date' => $payment->cheque_date?->toDateString() ?? '',
            'cheque_status' => $payment->cheque_status ?: 'pending',
            'party_cheque_search' => (string) ($sourcePayment?->cheque_no ?: $payment->cheque_no ?: $payment->reference),
            'party_cheque_payment_id' => $sourcePayment?->id,
        ];
    }

    private function adjustProductStock(int $productId, int $quantityDelta): void
    {
        if ($quantityDelta > 0) {
            Product::query()->whereKey($productId)->increment('stock_quantity', $quantityDelta);
        } elseif ($quantityDelta < 0) {
            Product::query()->whereKey($productId)->decrement('stock_quantity', abs($quantityDelta));
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">
            {{ $this->isEditing ? __('Edit Wholesale Restock Invoice') : __('Wholesale Restock Invoice') }}
        </h1>
        <p class="text-sm text-zinc-500">
            {{ $this->isEditing ? __('Update the supplier invoice, restock items, payment rows, and stock adjustments from the same purchase form.') : __('Record incoming warehouse accessories shipments, register supplier invoices, adjust purchase values and automatically update warehouse stock count.') }}
        </p>
    </div>

    <!-- Main Creation Form Grid -->
    <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <!-- Left Side: Invoice Items & Product Search -->
        <div class="flex flex-col gap-6">
            <!-- Details Header -->
            <div class="app-card p-5 grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="invoice_no" :label="__('Invoice Reference #')" required />
                <flux:input wire:model="date" :label="__('Restock Date')" type="date" required />
                <div>
                    <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Wholesale Vendor') }}</label>
                    <select wire:model.live="supplier_id" class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-950 shadow-sm outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                        <option value="">{{ __('Choose Supplier') }}</option>
                        @foreach ($this->suppliers as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }} ({{ $sup->company_name ?: 'Distributor' }})</option>
                        @endforeach
                    </select>
                    @error('supplier_id')
                        <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                    @if ($supplier_id)
                        <div class="mt-1.5 flex justify-end">
                            <a href="{{ route('parties.suppliers', ['supplier_id' => $supplier_id]) }}" target="_blank" class="text-xs text-violet-600 hover:underline font-semibold flex items-center gap-1">
                                {{ __('View Supplier Ledger') }}
                                <flux:icon.arrow-top-right-on-square class="size-3" />
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Cart Section -->
            <div class="app-card p-5 flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3 border-b border-zinc-100 pb-3">
                    <h3 class="font-display text-sm font-semibold text-zinc-900">{{ __('Invoice Restock Catalog Items') }}</h3>
                    <flux:button type="button" variant="ghost" size="sm" wire:click="openProductModal">
                        <flux:icon.plus class="size-4 mr-1 text-zinc-400" />
                        {{ __('Add Product') }}
                    </flux:button>
                </div>

                <!-- Product Autocomplete Search -->
                <div class="relative" x-data="{ open: false }" @click.away="open = false">
                    <div class="flex items-center gap-3 border border-zinc-200 rounded-2xl px-4 py-3 bg-zinc-50/50">
                        <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                        <input
                            wire:model.live.debounce.150ms="productSearch"
                            type="text"
                            placeholder="Scan Barcode or type Product name / SKU / Compatible Model..."
                            class="w-full bg-transparent text-sm text-zinc-950 focus:outline-none"
                            @focus="open = true"
                        />
                    </div>

                    <!-- Search dropdown results -->
                    @if (count($this->products) > 0)
                        <div x-cloak x-show="open" class="absolute z-40 inset-x-0 mt-2 rounded-2xl border border-zinc-100 bg-white p-2 shadow-xl max-h-60 overflow-y-auto scrollbar-none">
                            @foreach ($this->products as $p)
                                <button
                                    type="button"
                                    class="flex items-center justify-between w-full text-left rounded-xl p-3 hover:bg-zinc-50 transition"
                                    wire:click="selectProduct({{ $p->id }})"
                                    @click="open = false"
                                >
                                    <div>
                                        <p class="text-sm font-bold text-zinc-900">{{ $p->name }}</p>
                                        <p class="text-xs text-zinc-400 mt-0.5">SKU: {{ $p->sku }} | Model: {{ $p->compatible_models ?: 'General' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs font-semibold text-violet-600">Stock: {{ $p->stock_quantity }} {{ $p->unit?->short_name }}</span>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Cart Table / Rows -->
                <div class="flex flex-col gap-3">
                    @forelse ($cart as $index => $item)
                        <div class="flex flex-col gap-3 rounded-2xl border border-zinc-100 bg-zinc-50/30 p-4" wire:key="cart-item-{{ $index }}">
                            <div class="flex items-start justify-between">
                                <div>
                                    <a href="{{ route('products.show', $item['product_id']) }}" target="_blank" class="text-sm font-bold text-zinc-900 hover:text-violet-600 hover:underline flex items-center gap-1">
                                        {{ $item['name'] }}
                                        <flux:icon.arrow-top-right-on-square class="size-3 text-zinc-400" />
                                    </a>
                                    <span class="text-[10px] text-zinc-400 uppercase font-mono mt-0.5 block">SKU: {{ $item['sku'] }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="text-xs text-rose-500 hover:underline font-semibold"
                                    wire:click="removeCartRow({{ $index }})"
                                >
                                    Remove
                                </button>
                            </div>

                            <!-- Cart row parameters inputs -->
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="text-[10px] text-zinc-400 font-semibold tracking-wide uppercase">{{ __('Restock Qty') }}</label>
                                    <input
                                        type="number"
                                        value="{{ $item['quantity'] }}"
                                        wire:change="updateCartRow({{ $index }}, 'quantity', $event.target.value)"
                                        class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-950 focus:outline-none"
                                        required
                                    />
                                </div>

                                <div>
                                    <label class="text-[10px] text-zinc-400 font-semibold tracking-wide uppercase">{{ __('Unit Cost (Rs)') }}</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value="{{ $item['cost_price'] }}"
                                        wire:change="updateCartRow({{ $index }}, 'cost_price', $event.target.value)"
                                        class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-950 focus:outline-none"
                                        required
                                    />
                                </div>

                                <div>
                                    <label class="text-[10px] text-zinc-400 font-semibold tracking-wide uppercase">{{ __('Selling Price (Rs)') }}</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value="{{ $item['selling_price'] }}"
                                        wire:change="updateCartRow({{ $index }}, 'selling_price', $event.target.value)"
                                        class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-950 focus:outline-none"
                                        required
                                    />
                                </div>
                            </div>

                            <div class="flex items-center justify-between border-t border-zinc-100 pt-2 text-xs">
                                <span class="text-zinc-500">Row Subtotal</span>
                                <span class="font-bold text-zinc-950">Rs {{ number_format($item['subtotal'], 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="py-12 text-center text-xs text-zinc-400 bg-zinc-50/50 rounded-2xl border border-dashed border-zinc-200">
                            {{ __('Cart is empty. Search products above to build wholesale order.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right Side: Invoice Checkout Calculations -->
        <div class="flex flex-col gap-6">
            <div class="app-card p-5 flex flex-col gap-4">
                <div class="flex flex-col gap-1 border-b border-zinc-100 pb-3">
                    <h3 class="font-display text-sm font-semibold text-zinc-900">{{ __('Invoice Calculations') }}</h3>
                </div>

                <div class="flex flex-col gap-3 border-b border-zinc-100 pb-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Subtotal Amount</span>
                        <span class="font-semibold text-zinc-950">Rs {{ number_format($this->cartSubtotal, 2) }}</span>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <flux:input wire:model.live="discount" :label="__('Invoice Discount (Rs)')" type="number" step="0.01" />
                    </div>

                    <div class="flex justify-between border-t border-zinc-100 pt-3 text-base">
                        <span class="font-semibold text-zinc-900">Grand Total</span>
                        <span class="font-bold text-violet-600">Rs {{ number_format($this->cartTotal, 2) }}</span>
                    </div>
                </div>

                <!-- Split Payment Inputs -->
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Capture Outward Payment') }}</h4>
                        <div class="grid grid-cols-2 gap-2 sm:flex">
                            <flux:button type="button" size="sm" variant="ghost" icon="banknotes" wire:click="addPaymentRow('cash')">
                                {{ __('Cash') }}
                            </flux:button>
                            <flux:button type="button" size="sm" variant="ghost" icon="plus-circle" wire:click="addPaymentRow('cheque')">
                                {{ __('Cheque') }}
                            </flux:button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        @foreach ($paymentRows as $index => $paymentRow)
                            <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-3 sm:p-4" wire:key="purchase-payment-row-{{ $index }}">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-wider text-zinc-500">{{ __('Payment') }} #{{ $index + 1 }}</p>
                                        <p class="mt-0.5 text-[11px] text-zinc-400">{{ __('Cash, own cheque, saved party cheque, or manual party cheque') }}</p>
                                    </div>
                                    @if (count($paymentRows) > 1)
                                        <button type="button" wire:click="removePaymentRow({{ $index }})" class="rounded-lg px-2 py-1 text-xs font-bold text-rose-600 hover:bg-rose-50">
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:input wire:model.live.number="paymentRows.{{ $index }}.amount" :label="__('Amount (Rs)')" type="number" step="0.01" />
                                    <flux:select wire:model.live="paymentRows.{{ $index }}.method" :label="__('Paid Account')">
                                        <option value="cash">{{ __('Cash Account') }}</option>
                                        <option value="bank_transfer">{{ __('Direct Bank Transfer') }}</option>
                                        <option value="cheque">{{ __('Cheque Hold') }}</option>
                                    </flux:select>
                                </div>

                                @if (($paymentRow['method'] ?? 'cash') === 'cheque')
                                    <div class="mt-3 flex flex-col gap-3">
                                        <flux:select wire:model.live="paymentRows.{{ $index }}.cheque_type" :label="__('Cheque Type')">
                                            <option value="party">{{ __('Party Cheque') }}</option>
                                            <option value="own">{{ __('Own Cheque') }}</option>
                                        </flux:select>

                                        @if (($paymentRow['cheque_type'] ?? 'party') === 'party')
                                            <div class="relative" x-data="{ open: false }" @click.away="open = false">
                                                <flux:input wire:model.live.debounce.150ms="paymentRows.{{ $index }}.party_cheque_search" :label="__('Customer Cheque No')" placeholder="Search saved customer cheque..." @focus="open = true" />

                                                @php($rowPartyCheques = $this->partyChequesForRow($index))
                                                @if (count($rowPartyCheques) > 0)
                                                    <div x-cloak x-show="open" class="absolute z-40 mt-2 max-h-60 w-full overflow-y-auto rounded-2xl border border-zinc-100 bg-white p-2 shadow-xl">
                                                        @foreach ($rowPartyCheques as $partyCheque)
                                                            @php($partySale = $partyCheque->paymentable)
                                                            <button type="button" wire:click="selectPaymentRowPartyCheque({{ $index }}, {{ $partyCheque->id }})" @click="open = false" class="w-full rounded-xl p-3 text-left transition hover:bg-zinc-50">
                                                                <div class="flex items-center justify-between gap-3">
                                                                    <span class="text-sm font-bold text-zinc-900">{{ $partyCheque->cheque_no ?: $partyCheque->reference }}</span>
                                                                    <span class="text-xs font-black text-violet-600">Rs {{ number_format($partyCheque->amount, 2) }}</span>
                                                                </div>
                                                                <p class="mt-0.5 text-xs text-zinc-500">
                                                                    {{ $partySale instanceof \App\Models\Sale ? ($partySale->customer?->name ?? __('Unknown Customer')) : ($partySale->name ?? __('Unknown Customer')) }} · {{ $partySale instanceof \App\Models\Sale ? $partySale->invoice_no : __('Due Payoff') }} · {{ $partyCheque->cheque_date?->format('Y-m-d') }}
                                                                </p>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        @if (($paymentRow['cheque_type'] ?? 'party') === 'party' && ! empty($paymentRow['party_cheque_payment_id']))
                                            <div class="rounded-2xl border border-violet-100 bg-violet-50 p-3 text-xs font-semibold text-violet-800">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p class="font-black">{{ __('Saved customer cheque selected') }}</p>
                                                        <p class="mt-1">{{ $paymentRow['party_cheque_search'] }} · {{ __('Amount') }} Rs {{ number_format((float) ($paymentRow['amount'] ?? 0), 2) }}</p>
                                                    </div>
                                                    <button type="button" wire:click="clearPaymentRowPartyCheque({{ $index }})" class="shrink-0 rounded-lg bg-white px-2 py-1 text-[11px] font-black text-violet-700 shadow-sm">
                                                        {{ __('Change') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <flux:input wire:model.live.debounce.300ms="paymentRows.{{ $index }}.cheque_no" :label="($paymentRow['cheque_type'] ?? 'party') === 'own' ? __('Own Cheque No') : __('Party Cheque No')" placeholder="Cheque number" />
                                                </div>
                                                <flux:input wire:model.live="paymentRows.{{ $index }}.cheque_bank" :label="__('Bank')" placeholder="Bank name" />
                                            </div>
                                            <flux:input wire:model.live="paymentRows.{{ $index }}.cheque_date" :label="__('Cheque Date')" type="date" />
                                        @endif

                                        @if (($paymentRow['cheque_type'] ?? 'party') === 'party' && empty($paymentRow['party_cheque_payment_id']))
                                            <p class="rounded-2xl border border-sky-100 bg-sky-50 p-3 text-xs font-semibold text-sky-800">
                                                {{ __('Manual party cheque: type the cheque number, bank, date, and amount even if the customer cheque is not saved in the system.') }}
                                            </p>
                                        @elseif (($paymentRow['cheque_type'] ?? 'party') === 'party')
                                            <p class="rounded-2xl border border-violet-100 bg-violet-50 p-3 text-xs font-semibold text-violet-800">
                                                {{ __('Saved party cheque selected. Passing this supplier cheque will also settle the linked customer cheque.') }}
                                            </p>
                                        @else
                                            <p class="rounded-2xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                                                {{ __('Own cheques are shown on the dashboard before the cheque date and become cash out when marked passed.') }}
                                            </p>
                                        @endif
                                    </div>
                                @else
                                    <div class="mt-3">
                                        <flux:input wire:model.live="paymentRows.{{ $index }}.reference" :label="__('Transaction Receipt Reference')" placeholder="e.g. Bank slip #" />
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="flex justify-between text-sm rounded-2xl bg-zinc-50 p-4 border border-zinc-100">
                            <span class="text-zinc-500 font-medium">{{ __('Payment Total') }}</span>
                            <span class="font-bold text-zinc-950">Rs {{ number_format($this->paymentRowsTotalAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm rounded-2xl bg-zinc-50 p-4 border border-zinc-100">
                        <span class="text-zinc-500 font-medium">Outstanding Vendor Due</span>
                            <span class="font-bold text-rose-600">Rs {{ number_format($this->outstandingDue, 2) }}</span>
                        </div>
                    </div>

                    <flux:textarea wire:model="notes" :label="__('Internal restock details')" rows="2" />

                    <flux:button type="button" wire:click="savePurchase" variant="primary" class="w-full mt-2">
                        <flux:icon.check class="size-4 mr-1" />
                        {{ $this->isEditing ? __('Update Restock Invoice') : __('Record Restock Invoice') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </div>
</div>

<flux:modal wire:model.self="productCreateOpen">
    <div class="w-full max-w-3xl space-y-4">
        <div>
            <h3 class="font-display text-lg font-semibold text-zinc-950">{{ __('Add new product') }}</h3>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Create a product without stock quantity. You can set the minimum stock alert here.') }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="newProductName" :label="__('Product name')" required />
            <flux:input wire:model="newProductSku" :label="__('SKU / Code')" placeholder="Leave blank to auto-generate" />
            <flux:input wire:model="newProductBarcode" :label="__('Barcode')" placeholder="Leave blank to sync with SKU" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="newProductCategoryId" :label="__('Category')">
                <flux:select.option value="">{{ __('Uncategorized') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="newProductBrandId" :label="__('Brand')">
                <flux:select.option value="">{{ __('No brand') }}</flux:select.option>
                @foreach ($this->brands as $brand)
                    <flux:select.option :value="$brand->id">{{ $brand->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="newProductUnitId" :label="__('Unit')">
                <flux:select.option value="">{{ __('No unit') }}</flux:select.option>
                @foreach ($this->units as $unit)
                    <flux:select.option :value="$unit->id">{{ $unit->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="newProductCompatibleModels" :label="__('Compatible models')" />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="newProductCostPrice" type="number" step="0.01" :label="__('Cost price')" required />
            <flux:input wire:model="newProductSellingPrice" @input="$wire.set('newProductWholesalePrice', $event.target.value)" type="number" step="0.01" :label="__('Selling price')" required />
            <flux:input wire:model="newProductWholesalePrice" type="number" step="0.01" :label="__('Wholesale price')" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="newProductMinimumStock" type="number" min="0" :label="__('Minimum stock alert')" />
        </div>

        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" wire:click="closeProductModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="saveNewProduct">
                {{ __('Save product') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
