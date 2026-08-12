<?php

use App\Models\Customer;
use App\Models\HoldOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\ChequePaymentService;
use App\Services\SmsNotificationService;
use App\Services\TextItSmsService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('POS Terminal')] class extends Component
{
    // Filter parameters
    public string $barcodeInput = '';

    // Cart items state
    public array $cart = [];

    // Checkout configurations
    public int|string $customer_id = '';
    public $discount = 0.00;
    public string $discount_type = 'fixed'; // fixed, percentage
    public $tax = 0.00;
    public $paid_amount = 0.00;
    public string $payment_method = 'cash';
    public string $payment_reference = '';
    public string $cheque_bank = '';
    public string $cheque_no = '';
    public string $cheque_date = '';
    public string $saleDate = '';
    public array $paymentRows = [];
    public string $notes = '';
    public string $customerSearch = '';
    public string $returnSearch = '';
    public array $returnCredits = [];
    public string $quickCustomerName = '';
    public string $quickCustomerPhone = '';
    public string $quickCustomerEmail = '';
    public string $quickCustomerAddress = '';
    public bool $allowNegativeStock = false;

    // Modals & overlay control
    public bool $checkoutOpen = false;
    public bool $cartDrawerOpen = false;
    public bool $successOpen = false;
    public bool $cartItemEditorOpen = false;
    public bool $customerCreateOpen = false;
    public ?int $completedSaleId = null;
    public ?int $editingCartIndex = null;
    public bool $editingNewCartItem = false;
    public string $editCartName = '';
    public int $editQuantity = 1;
    public $editUnitPrice = 0.00;
    public string $editDiscountType = 'fixed';
    public $editDiscountValue = 0.00;

    public ?Sale $editingSale = null;

    // Hold Orders list
    public array $heldOrders = [];

    private function calculateCartItemSubtotal(array $item): float
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPrice = max(0, (float) ($item['selling_price'] ?? 0));
        $grossTotal = $quantity * $unitPrice;
        $discountType = $item['discount_type'] ?? 'fixed';
        $discountValue = max(0, (float) ($item['discount_value'] ?? 0));
        $discountAmount = $discountType === 'percentage'
            ? $grossTotal * min($discountValue, 100) / 100
            : min($discountValue, $grossTotal);

        return round(max(0, $grossTotal - $discountAmount), 2);
    }

    private function syncCartItemSubtotal(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $this->cart[$index]['subtotal'] = $this->calculateCartItemSubtotal($this->cart[$index]);
    }

    private function findSellableProduct(int $productId): Product
    {
        return Product::query()
            ->select([
                'id',
                'name',
                'sku',
                'cost_price',
                'selling_price',
                'wholesale_price',
                'stock_quantity',
            ])
            ->findOrFail($productId);
    }

    public function mount(?Sale $sale = null): void
    {
        // Require authorization
        if (! auth()->user()->hasPermission('access_pos')) {
            abort(403, 'Unauthorized terminal access.');
        }

        $this->allowNegativeStock = Setting::get('pos_allow_negative_stock', '0') !== '0';
        $this->paymentRows = [$this->blankPaymentRow()];
        $this->saleDate = today()->toDateString();

        if ($sale && $sale->exists) {
            $this->editingSale = $sale;
            $this->customer_id = $sale->customer_id;
            $this->discount = (float) $sale->discount_amount;
            $this->discount_type = 'fixed';
            $this->tax = (float) $sale->tax_amount;
            $this->notes = $sale->notes ?? '';
            $this->saleDate = $sale->date?->toDateString() ?? today()->toDateString();

            // Add items to cart
            foreach ($sale->items as $item) {
                $product = $item->product;
                if ($product) {
                    if ((int) $item->quantity < 0) {
                        continue;
                    }

                    $this->cart[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'cost_price' => (float) $item->cost_price,
                        'selling_price' => (float) $item->selling_price,
                        'retail_price' => (float) $product->selling_price,
                        'wholesale_price' => (float) ($product->wholesale_price ?? $product->selling_price),
                        'price_type' => 'custom',
                        'quantity' => (int) $item->quantity,
                        'discount_type' => 'fixed',
                        'discount_value' => 0.00,
                        'subtotal' => (float) $item->subtotal,
                        'stock' => $product->stock_quantity,
                    ];
                }
            }

            // Load Return Credits for this checkout
            $saleReturns = SaleReturn::query()
                ->with('items.product')
                ->where('notes', 'like', 'Return credited on checkout ' . $sale->invoice_no . '.%')
                ->get();

            foreach ($saleReturns as $saleReturn) {
                $originalSale = Sale::query()->find($saleReturn->sale_id);
                foreach ($saleReturn->items as $returnItem) {
                    $key = $this->returnCreditKey($saleReturn->sale_id, $returnItem->product_id);

                    $saleItemForMax = SaleItem::query()
                        ->where('sale_id', $saleReturn->sale_id)
                        ->where('product_id', $returnItem->product_id)
                        ->where('quantity', '>', 0)
                        ->first();

                    $max = $saleItemForMax ? $this->maxReturnableQuantityForSaleItem($saleItemForMax) + $returnItem->quantity : $returnItem->quantity;

                    $this->returnCredits[$key] = [
                        'sale_id' => $saleReturn->sale_id,
                        'invoice_no' => (string) ($originalSale ? $originalSale->invoice_no : ''),
                        'product_id' => $returnItem->product_id,
                        'name' => (string) ($returnItem->product?->name ?? __('Unknown product')),
                        'sku' => (string) ($returnItem->product?->sku ?? ''),
                        'quantity' => (int) $returnItem->quantity,
                        'max' => $max,
                        'return_price' => (float) $returnItem->refund_price,
                        'unit_cost' => (float) ($returnItem->product?->cost_price ?? 0),
                        'subtotal' => (float) $returnItem->subtotal,
                    ];
                }
            }

            $this->paid_amount = (float) $sale->paid_amount;
        } else {
            $defaultCust = Customer::query()->where('name', 'Walk-in Customer')->first();
            if ($defaultCust) {
                $this->customer_id = $defaultCust->id;
            }
        }

        Cache::remember(
            'pos:auto-pass-overdue-cheques:' . today()->toDateString(),
            now()->addMinutes(15),
            fn() => app(ChequePaymentService::class)->autoPassOverduePendingCheques()
        );
        $this->loadHeldOrders();
    }

    public function loadHeldOrders(): void
    {
        $this->heldOrders = HoldOrder::query()
            ->with('customer:id,name,phone')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->toArray();
    }

    public function handleBarcodeInput(): void
    {
        if (empty($this->barcodeInput)) {
            return;
        }

        $product = Product::query()
            ->select('id')
            ->where('barcode', $this->barcodeInput)
            ->orWhere('sku', $this->barcodeInput)
            ->first();

        if ($product) {
            $this->addToCart($product->id);
        } else {
            Flux::toast(variant: 'danger', text: __('Product SKU/Barcode not found.'));
        }

        $this->barcodeInput = '';
    }

    public function addToCart(int $productId): void
    {
        $product = $this->findSellableProduct($productId);

        if ($product->stock_quantity <= 0 && ! $this->allowNegativeStock) {
            Flux::toast(variant: 'danger', text: __('Out of stock! Negative stock sales disabled.'));
            return;
        }

        // Check if already in cart
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $product->id) {
                // Check stock bounds
                if ($item['quantity'] + 1 > $product->stock_quantity && ! $this->allowNegativeStock) {
                    Flux::toast(variant: 'danger', text: __('Cannot exceed warehouse stock limits.'));
                    return;
                }

                $this->cart[$index]['quantity']++;
                $this->syncCartItemSubtotal($index);
                $this->paid_amount = $this->cartTotal;
                $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
                $this->dispatch('play-beep');
                return;
            }
        }

        // Add new item
        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'cost_price' => (float) $product->cost_price,
            'selling_price' => (float) $product->selling_price,
            'retail_price' => (float) $product->selling_price,
            'wholesale_price' => (float) ($product->wholesale_price ?? $product->selling_price),
            'price_type' => 'retail', // retail, wholesale
            'quantity' => 1,
            'discount_type' => 'fixed',
            'discount_value' => 0.00,
            'subtotal' => (float) $product->selling_price,
            'stock' => $product->stock_quantity,
        ];

        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        $this->dispatch('play-beep');
    }

    public function openProductCartEditor(int $productId): void
    {
        $product = $this->findSellableProduct($productId);

        if ($product->stock_quantity <= 0 && ! $this->allowNegativeStock) {
            Flux::toast(variant: 'danger', text: __('Out of stock! Negative stock sales disabled.'));
            return;
        }

        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $product->id) {
                $this->editingNewCartItem = false;
                $this->openCartItemEditor($index);
                return;
            }
        }

        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'cost_price' => (float) $product->cost_price,
            'selling_price' => (float) $product->selling_price,
            'retail_price' => (float) $product->selling_price,
            'wholesale_price' => (float) ($product->wholesale_price ?? $product->selling_price),
            'price_type' => 'retail',
            'quantity' => 1,
            'discount_type' => 'fixed',
            'discount_value' => 0.00,
            'subtotal' => (float) $product->selling_price,
            'stock' => $product->stock_quantity,
        ];

        $this->editingNewCartItem = true;
        $this->openCartItemEditor(array_key_last($this->cart));
    }

    public function updateCartQty(int $index, int|string|null $qty): void
    {
        if (isset($this->cart[$index])) {
            $typedQuantity = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newQty = $typedQuantity === false ? 1 : $typedQuantity;
            $stockQuantity = (int) ($this->cart[$index]['stock'] ?? 0);

            if ($newQty > $stockQuantity && ! $this->allowNegativeStock) {
                Flux::toast(variant: 'danger', text: __('Cannot exceed warehouse stock limits.'));
                return;
            }

            $this->cart[$index]['quantity'] = $newQty;
            $this->syncCartItemSubtotal($index);
            $this->paid_amount = $this->cartTotal;
            $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        }
    }

    public function togglePriceType(int $index): void
    {
        if (isset($this->cart[$index])) {
            $item = $this->cart[$index];
            if ($item['price_type'] === 'retail') {
                $this->cart[$index]['price_type'] = 'wholesale';
                $this->cart[$index]['selling_price'] = $item['wholesale_price'];
            } else {
                $this->cart[$index]['price_type'] = 'retail';
                $this->cart[$index]['selling_price'] = $item['retail_price'];
            }

            $this->syncCartItemSubtotal($index);
            $this->paid_amount = $this->cartTotal;
            $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        }
    }

    public function openCartItemEditor(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        $item = $this->cart[$index];

        $this->editingCartIndex = $index;
        $this->editCartName = $item['name'];
        $this->editQuantity = (int) $item['quantity'];
        $this->editUnitPrice = (float) $item['selling_price'];
        $this->editDiscountType = $item['discount_type'] ?? 'fixed';
        $this->editDiscountValue = (float) ($item['discount_value'] ?? 0);
        $this->cartItemEditorOpen = true;
    }

    public function closeCartItemEditor(): void
    {
        if ($this->editingNewCartItem && $this->editingCartIndex !== null && isset($this->cart[$this->editingCartIndex])) {
            unset($this->cart[$this->editingCartIndex]);
            $this->cart = array_values($this->cart);
            $this->paid_amount = $this->cartTotal;
            $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        }

        $this->editingNewCartItem = false;
        $this->reset('cartItemEditorOpen', 'editingCartIndex', 'editCartName', 'editQuantity', 'editUnitPrice', 'editDiscountValue');
        $this->editDiscountType = 'fixed';
    }

    public function saveCartItemEditor(): void
    {
        $validated = $this->validate([
            'editingCartIndex' => 'required|integer|min:0',
            'editQuantity' => 'required|integer|min:1',
            'editUnitPrice' => 'required|numeric|min:0',
            'editDiscountType' => 'required|in:fixed,percentage',
            'editDiscountValue' => 'required|numeric|min:0',
        ]);

        $index = (int) $validated['editingCartIndex'];

        if (! isset($this->cart[$index])) {
            $this->closeCartItemEditor();
            return;
        }

        $stockQuantity = (int) ($this->cart[$index]['stock'] ?? 0);

        if ($this->editQuantity > $stockQuantity && ! $this->allowNegativeStock) {
            Flux::toast(variant: 'danger', text: __('Cannot exceed warehouse stock limits.'));
            return;
        }

        $this->cart[$index]['quantity'] = $this->editQuantity;
        $this->cart[$index]['selling_price'] = round($this->editUnitPrice, 2);
        $discountValue = $this->editDiscountType === 'percentage'
            ? min((float) $this->editDiscountValue, 100)
            : (float) $this->editDiscountValue;

        $this->cart[$index]['discount_type'] = $this->editDiscountType;
        $this->cart[$index]['discount_value'] = round($discountValue, 2);
        $this->cart[$index]['price_type'] = match (round($this->editUnitPrice, 2)) {
            round((float) $this->cart[$index]['retail_price'], 2) => 'retail',
            round((float) $this->cart[$index]['wholesale_price'], 2) => 'wholesale',
            default => 'custom',
        };
        $this->syncCartItemSubtotal($index);

        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        $this->editingNewCartItem = false;
        $this->closeCartItemEditor();
        $this->dispatch('play-beep');
    }

    public function saveCartItemEditorFromPayload(int $quantity, float $unitPrice, string $discountType, float $discountValue): void
    {
        $this->editQuantity = max(1, $quantity);
        $this->editUnitPrice = max(0, $unitPrice);
        $this->editDiscountType = $discountType === 'percentage' ? 'percentage' : 'fixed';
        $this->editDiscountValue = max(0, $discountValue);

        $this->saveCartItemEditor();
    }

    public function openQuickCustomerModal(): void
    {
        $this->quickCustomerName = $this->customerSearch;
        $this->customerCreateOpen = true;
    }

    public function closeQuickCustomerModal(): void
    {
        $this->reset('customerCreateOpen', 'quickCustomerName', 'quickCustomerPhone', 'quickCustomerEmail', 'quickCustomerAddress');
    }

    public function saveQuickCustomer(): void
    {
        $validated = $this->validate([
            'quickCustomerName' => 'required|string|max:100',
            'quickCustomerPhone' => 'nullable|string|max:20',
            'quickCustomerEmail' => 'nullable|email|max:100',
            'quickCustomerAddress' => 'nullable|string|max:500',
        ]);

        $customer = Customer::query()->create([
            'name' => $validated['quickCustomerName'],
            'phone' => $validated['quickCustomerPhone'] ?? null,
            'email' => $validated['quickCustomerEmail'] ?? null,
            'address' => $validated['quickCustomerAddress'] ?? null,
            'opening_balance' => 0,
            'due_balance' => 0,
        ]);

        $this->customer_id = $customer->id;
        $this->customerSearch = '';
        $this->closeQuickCustomerModal();

        ActivityLogger::log('customer_create', "Registered new customer from POS: {$customer->name}.");
        Flux::toast(variant: 'success', text: __('Customer added to this checkout.'));
    }


    public function removeCartRow(int $index): void
    {
        if (isset($this->cart[$index])) {
            unset($this->cart[$index]);
            $this->cart = array_values($this->cart);
            $this->paid_amount = $this->cartTotal;
            $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
        }
    }

    public function holdOrder(): void
    {
        if (count($this->cart) === 0) {
            Flux::toast(variant: 'danger', text: __('Cart is empty. Nothing to hold.'));
            return;
        }

        HoldOrder::query()->create([
            'hold_no' => 'HOLD-' . rand(1000, 9999),
            'customer_id' => filled($this->customer_id) ? $this->customer_id : null,
            'items_json' => $this->cart,
            'subtotal' => $this->cartSubtotal,
            'discount' => $this->cartDiscountAmount,
            'tax' => (float) $this->tax,
            'total' => $this->cartTotal,
            'notes' => $this->notes ?: 'Held order session.',
        ]);

        ActivityLogger::log('pos_hold', 'Held current customer shopping session.');
        Flux::toast(variant: 'success', text: __('Cart saved on hold queue.'));

        $this->resetCart();
        $this->loadHeldOrders();
    }

    public function resumeHeldOrder(int $holdId): void
    {
        $hold = HoldOrder::query()->findOrFail($holdId);
        $this->customer_id = $hold->customer_id ?? '';
        $this->cart = $hold->items_json;
        $this->discount = (float) $hold->discount;
        $this->tax = (float) $hold->tax;
        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();

        $hold->delete();
        $this->loadHeldOrders();

        Flux::toast(variant: 'success', text: __('Cart session resumed.'));
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

        $this->syncFirstPaymentRowToLegacy();
    }

    public function addReturnCredit(int $saleItemId): void
    {
        if (blank($this->customer_id)) {
            $this->addError('customer_id', __('Select the customer before adding a return.'));
            return;
        }

        $saleItem = SaleItem::query()
            ->with(['sale:id,customer_id,invoice_no,date', 'product:id,name,sku,stock_quantity'])
            ->whereHas('sale', fn($query) => $query->where('customer_id', $this->customer_id))
            ->findOrFail($saleItemId);

        $maxReturnable = $this->maxReturnableQuantityForSaleItem($saleItem);

        if ($maxReturnable <= 0) {
            Flux::toast(variant: 'danger', text: __('This sold product has already been fully returned.'));
            return;
        }

        $key = $this->returnCreditKey($saleItem->sale_id, (int) $saleItem->product_id);

        if (isset($this->returnCredits[$key])) {
            $this->updateReturnCreditQty($key, (int) $this->returnCredits[$key]['quantity'] + 1);
            return;
        }

        $this->returnCredits[$key] = [
            'sale_id' => (int) $saleItem->sale_id,
            'invoice_no' => (string) $saleItem->sale?->invoice_no,
            'product_id' => (int) $saleItem->product_id,
            'name' => (string) ($saleItem->product?->name ?? __('Unknown product')),
            'sku' => (string) ($saleItem->product?->sku ?? ''),
            'quantity' => 1,
            'max' => $maxReturnable,
            'return_price' => (float) $saleItem->selling_price,
            'unit_cost' => (float) $saleItem->cost_price,
            'subtotal' => round((float) $saleItem->selling_price, 2),
        ];

        $this->returnSearch = '';
        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
    }

    public function updateReturnCreditQty(string $key, int|string|null $quantity): void
    {
        if (! isset($this->returnCredits[$key])) {
            return;
        }

        $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = $quantity === false ? 1 : $quantity;
        $quantity = min((int) $this->returnCredits[$key]['max'], $quantity);

        $this->returnCredits[$key]['quantity'] = $quantity;
        $this->syncReturnCreditSubtotal($key);
    }

    public function updateReturnCreditPrice(string $key, int|float|string|null $returnPrice): void
    {
        if (! isset($this->returnCredits[$key])) {
            return;
        }

        $this->returnCredits[$key]['return_price'] = round(max(0, (float) $returnPrice), 2);
        $this->syncReturnCreditSubtotal($key);
    }

    public function removeReturnCredit(string $key): void
    {
        unset($this->returnCredits[$key]);
        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
    }

    public function updatedPaidAmount(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedPaymentMethod(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedPaymentReference(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedChequeBank(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedChequeNo(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedChequeDate(): void
    {
        $this->syncLegacyPaymentToFirstRow();
    }

    public function updatedCustomerId(): void
    {
        $this->returnCredits = [];
        $this->returnSearch = '';
        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
    }

    public function submitCheckout(SmsNotificationService $smsNotificationService): void
    {

        $rules = [
            'customer_id' => 'required|exists:customers,id',
            'saleDate' => 'required|date',
            'cart' => 'array',
            'returnCredits' => 'array',
            'discount' => 'required|numeric|min:0',
            'tax' => 'required|numeric|min:0',
            'paymentRows' => 'required|array|min:1',
            'paymentRows.*.amount' => 'required|numeric|min:0',
            'paymentRows.*.method' => 'required|in:cash,card,qr,bank_transfer,cheque',
            'paymentRows.*.reference' => 'nullable|string|max:100',
            'paymentRows.*.cheque_bank' => 'nullable|string|max:100',
            'paymentRows.*.cheque_no' => 'nullable|string|max:100',
            'paymentRows.*.cheque_date' => 'nullable|date',
        ];

        $this->validate($rules);

        if (count($this->cart) === 0 && count($this->returnCredits) === 0) {
            $this->addError('cart', __('Add at least one sale product or return item.'));
            return;
        }

        if (count($this->returnCredits) > 0) {
            $customer = Customer::query()->find($this->customer_id);

            if (! $customer || $customer->phone === '0000000000' || strtolower($customer->name) === 'walk-in customer') {
                $this->addError('customer_id', __('Select the real customer before adding return items.'));
                return;
            }

            foreach ($this->returnCredits as $key => $returnCredit) {
                $saleItem = SaleItem::query()
                    ->with('sale:id,customer_id')
                    ->where('sale_id', $returnCredit['sale_id'] ?? 0)
                    ->where('product_id', $returnCredit['product_id'] ?? 0)
                    ->whereHas('sale', fn($query) => $query->where('customer_id', $this->customer_id))
                    ->first();

                if (! $saleItem || (int) ($returnCredit['quantity'] ?? 0) > $this->maxReturnableQuantityForSaleItem($saleItem)) {
                    $this->addError('returnCredits', __('One of the return items is no longer available to return.'));
                    return;
                }

                $this->syncReturnCreditSubtotal((string) $key);
            }
        }

        $paymentRows = $this->normalisedPaymentRows();
        $hasChequePayment = collect($paymentRows)->contains(fn(array $paymentRow): bool => $paymentRow['method'] === 'cheque');

        if ($hasChequePayment) {
            $customer = Customer::query()->find($this->customer_id);
            if (! $customer || $customer->phone === '0000000000' || strtolower($customer->name) === 'walk-in customer') {
                $this->addError('customer_id', __('Cheque payments are not allowed for Walk-in Customer. Please select a registered customer.'));
                return;
            }
        }

        foreach ($paymentRows as $index => $paymentRow) {
            if ($paymentRow['method'] === 'cheque' && blank($paymentRow['cheque_date'])) {
                $this->addError("paymentRows.{$index}.cheque_date", __('Cheque date is required.'));
                return;
            }
        }

        $subtotal = $this->cartSubtotal;
        $grandTotal = $this->cartTotal;

        if ($this->paymentRowsTotal($paymentRows) > $grandTotal) {
            $this->addError('paymentRows', __('Payment rows cannot exceed the sale grand total.'));
            return;
        }

        $capturedPaidAmount = $this->capturedPaymentRowsTotal($paymentRows);
        $heldChequeAmount = $this->chequePaymentRowsTotal($paymentRows);
        $dueAmount = max(0.00, $grandTotal - $capturedPaidAmount - $heldChequeAmount);

        if ($dueAmount > 0 && Setting::get('pos_allow_due_sale', '1') === '0') {
            Flux::toast(variant: 'danger', text: __('Due sales disabled in system settings. Full payment required.'));
            return;
        }

        $paymentStatus = 'due';
        if ($heldChequeAmount > 0) {
            $paymentStatus = $dueAmount > 0 ? 'partial' : 'cheque_pending';
        } elseif ($capturedPaidAmount >= $grandTotal) {
            $paymentStatus = 'paid';
        } elseif ($capturedPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        // Compute total product costs to register net profits
        $totalCost = 0.00;
        foreach ($this->cart as $item) {
            $totalCost += $item['quantity'] * $item['cost_price'];
        }
        foreach ($this->returnCredits as $returnCredit) {
            $totalCost -= (int) $returnCredit['quantity'] * (float) $returnCredit['unit_cost'];
        }
        $netProfit = $grandTotal - $totalCost;

        $sale = null;
        $invoiceNo = '';

        $isEditingSale = (bool) $this->editingSale;

        if ($this->editingSale) {
            $sale = $this->editingSale;
            $invoiceNo = $sale->invoice_no;

            // Revert original stock
            foreach ($sale->items as $oldItem) {
                $p = Product::query()->find($oldItem->product_id);
                if ($p) $p->increment('stock_quantity', $oldItem->quantity);
            }

            // Reverse old customer due balance
            $oldCustomer = Customer::query()->find($sale->customer_id);
            if ($oldCustomer && $sale->due_amount > 0) {
                $oldCustomer->decrement('due_balance', $sale->due_amount);
            }

            // Delete old SaleReturn records associated with this checkout
            $oldReturns = SaleReturn::query()->where('notes', 'like', 'Return credited on checkout ' . $invoiceNo . '.%')->get();
            foreach ($oldReturns as $oldReturn) {
                $oldReturn->items()->delete();
                $oldReturn->delete();
            }

            $sale->items()->delete();
            $sale->payments()->delete();

            $sale->update([
                'customer_id' => $this->customer_id,
                'date' => $this->saleDate,
                'subtotal_amount' => $subtotal,
                'discount_amount' => (float) $this->cartDiscountAmount,
                'tax_amount' => (float) $this->tax,
                'grand_total' => $grandTotal,
                'paid_amount' => $capturedPaidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'profit' => $netProfit,
                'notes' => $this->notes,
            ]);
        } else {
            // Generate Invoice Number
            $startingNo = (int) Setting::get('invoice_starting_no', '1001');
            $prefix = Setting::get('invoice_prefix', 'INV-');
            $invoiceCount = Sale::query()->count();
            $invoiceNo = $prefix . ($startingNo + $invoiceCount);

            // 1. Create Sale invoice
            $sale = Sale::query()->create([
                'customer_id' => $this->customer_id,
                'invoice_no' => $invoiceNo,
                'date' => $this->saleDate,
                'subtotal_amount' => $subtotal,
                'discount_amount' => (float) $this->cartDiscountAmount,
                'tax_amount' => (float) $this->tax,
                'grand_total' => $grandTotal,
                'paid_amount' => $capturedPaidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
                'profit' => $netProfit,
                'notes' => $this->notes,
            ]);
        }

        // 2. Process items sold
        foreach ($this->cart as $item) {
            $sale->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'cost_price' => $item['cost_price'],
                'selling_price' => $item['selling_price'],
                'subtotal' => $item['subtotal'],
            ]);

            // Deduct from physical store inventory stock
            $product = Product::query()->findOrFail($item['product_id']);
            $product->decrement('stock_quantity', $item['quantity']);
        }

        foreach ($this->returnCredits as $returnCredit) {
            $quantity = (int) $returnCredit['quantity'];
            $subtotal = round((float) $returnCredit['subtotal'], 2);

            $sale->items()->create([
                'product_id' => $returnCredit['product_id'],
                'quantity' => -$quantity,
                'cost_price' => $returnCredit['unit_cost'],
                'selling_price' => $returnCredit['return_price'],
                'subtotal' => -$subtotal,
            ]);

            $product = Product::query()->findOrFail($returnCredit['product_id']);
            $product->increment('stock_quantity', $quantity);
        }

        $this->recordCheckoutReturnCredits($sale);

        // 3. Log cashier polymorphic payment
        foreach ($paymentRows as $paymentRow) {
            if ($paymentRow['amount'] <= 0) {
                continue;
            }

            $isChequePayment = $paymentRow['method'] === 'cheque';

            $sale->payments()->create([
                'amount' => $paymentRow['amount'],
                'payment_method' => $paymentRow['method'],
                'date' => $this->saleDate,
                'reference' => $isChequePayment ? $paymentRow['cheque_no'] : $paymentRow['reference'],
                'cheque_bank' => $isChequePayment ? $paymentRow['cheque_bank'] : null,
                'cheque_no' => $isChequePayment ? $paymentRow['cheque_no'] : null,
                'cheque_date' => $isChequePayment ? $paymentRow['cheque_date'] : null,
                'cheque_status' => $isChequePayment ? 'pending' : null,
                'notes' => $isChequePayment ? 'POS cheque payment on hold until cleared.' : 'POS Terminal Sale Checkout.',
            ]);
        }

        // 4. Update customer outstanding receivables account
        $customer = Customer::query()->findOrFail($this->customer_id);
        if ($dueAmount > 0) {
            $customer->increment('due_balance', $dueAmount);
        }

        ActivityLogger::log('pos_sale', "Completed Checkout {$invoiceNo}. Grand Total: Rs {$grandTotal}, Cashier: " . auth()->user()->name);

        $this->completedSaleId = $sale->id;
        $this->checkoutOpen = false;
        $this->cartDrawerOpen = false;
        $this->successOpen = true;

        if (! $isEditingSale) {
            $smsNotificationService->notifySaleCreated($sale);
        }

        $this->resetCart();
    }

    public function triggerSMSNotification(TextItSmsService $smsService): void
    {
        if (! $this->completedSaleId) {
            return;
        }

        $sale = Sale::query()->with('customer')->findOrFail($this->completedSaleId);
        $customer = $sale->customer;

        if (empty($customer->phone) || $customer->phone === '0000000000') {
            Flux::toast(variant: 'danger', text: __('Customer phone is missing or walk-in.'));
            return;
        }

        $template = Setting::get('sms_template_sale');
        $msg = $smsService->parseTemplate($template, $smsService->saleTemplateData($sale));

        $result = $smsService->sendSms($customer->phone, $msg, 'SALE-MAN-' . $sale->id);

        if ($result['success']) {
            Flux::toast(variant: 'success', text: __('Invoice SMS confirmation dispatched.'));
        } else {
            Flux::toast(variant: 'danger', text: $result['message']);
        }
    }

    public function resetCart(): void
    {
        $this->reset('cart', 'returnCredits', 'returnSearch', 'discount', 'discount_type', 'tax', 'paid_amount', 'payment_method', 'payment_reference', 'cheque_bank', 'cheque_no', 'cheque_date', 'notes', 'customerSearch', 'paymentRows');
        $this->saleDate = today()->toDateString();
        $this->paymentRows = [$this->blankPaymentRow()];
        $defaultCust = Customer::query()->where('name', 'Walk-in Customer')->first();
        $this->customer_id = $defaultCust ? $defaultCust->id : '';
    }

    public function closeSuccess(): void
    {
        $this->successOpen = false;
        $this->completedSaleId = null;
        $this->resetCart();
    }

    #[Computed]
    public function customers(): Collection
    {
        $search = trim($this->customerSearch);

        $customers = Customer::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($customerQuery) use ($search) {
                    $customerQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderByRaw('CASE WHEN due_balance > 0 THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->limit(25)
            ->get();

        if (filled($this->customer_id)) {
            $selectedCustomer = Customer::query()->find($this->customer_id);

            if ($selectedCustomer && ! $customers->contains('id', $selectedCustomer->id)) {
                $customers->prepend($selectedCustomer);
            }
        }

        return $customers->unique('id')->values();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        if (blank($this->customer_id)) {
            return null;
        }

        return Customer::query()->find($this->customer_id);
    }

    #[Computed]
    public function returnCandidates(): Collection
    {
        if (blank($this->customer_id)) {
            return collect();
        }

        $search = trim($this->returnSearch);

        if ($search === '') {
            return collect();
        }

        return SaleItem::query()
            ->with(['sale:id,customer_id,invoice_no,date', 'product:id,name,sku'])
            ->where('quantity', '>', 0)
            ->whereHas('sale', fn($query) => $query->where('customer_id', $this->customer_id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('product', function ($query) use ($search): void {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('sku', 'like', '%' . $search . '%');
                    })
                        ->orWhereHas('sale', fn($query) => $query->where('invoice_no', 'like', '%' . $search . '%'));
                });
            })
            ->orderByDesc(
                Sale::query()
                    ->select('date')
                    ->whereColumn('sales.id', 'sale_items.sale_id')
                    ->limit(1)
            )
            ->orderByDesc('sale_items.id')
            ->limit(8)
            ->get()
            ->filter(fn(SaleItem $saleItem): bool => $this->maxReturnableQuantityForSaleItem($saleItem) > 0)
            ->values();
    }

    #[Computed]
    public function cartSubtotal()
    {
        return round(array_reduce($this->cart, fn($carry, $item) => $carry + $item['subtotal'], 0.00) - $this->returnCreditsTotal, 2);
    }

    #[Computed]
    public function returnCreditsTotal()
    {
        return round((float) collect($this->returnCredits)->sum('subtotal'), 2);
    }

    #[Computed]
    public function cartDiscountAmount()
    {
        if ($this->discount_type === 'percentage') {
            return ($this->cartSubtotal * (float) $this->discount) / 100.00;
        }
        return (float) $this->discount;
    }

    #[Computed]
    public function cartTotal()
    {
        return round(max(0.00, ($this->cartSubtotal + (float) $this->tax) - $this->cartDiscountAmount), 2);
    }

    #[Computed]
    public function checkoutDuePreview()
    {
        return max(0.00, $this->cartTotal - $this->paymentRowsTotalAmount);
    }

    #[Computed]
    public function paymentRowsTotalAmount()
    {
        return $this->paymentRowsTotal($this->normalisedPaymentRows());
    }

    #[Computed]
    public function checkoutHoldPreview()
    {
        return $this->chequePaymentRowsTotal($this->normalisedPaymentRows());
    }

    private function blankPaymentRow(string $method = 'cash'): array
    {
        return [
            'amount' => 0.00,
            'method' => $method,
            'reference' => '',
            'cheque_bank' => '',
            'cheque_no' => '',
            'cheque_date' => '',
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
        $this->paymentRows[0]['cheque_bank'] = $this->cheque_bank;
        $this->paymentRows[0]['cheque_no'] = $this->cheque_no;
        $this->paymentRows[0]['cheque_date'] = $this->cheque_date;
    }

    private function syncLegacyPaymentToFirstRowWhenOnlyOneRow(): void
    {
        if (count($this->paymentRows) !== 1) {
            return;
        }

        $this->syncLegacyPaymentToFirstRow();
    }

    private function syncFirstPaymentRowToLegacy(): void
    {
        $firstRow = $this->paymentRows[0] ?? $this->blankPaymentRow();

        $this->paid_amount = (float) ($firstRow['amount'] ?? 0);
        $this->payment_method = (string) ($firstRow['method'] ?? 'cash');
        $this->payment_reference = (string) ($firstRow['reference'] ?? '');
        $this->cheque_bank = (string) ($firstRow['cheque_bank'] ?? '');
        $this->cheque_no = (string) ($firstRow['cheque_no'] ?? '');
        $this->cheque_date = (string) ($firstRow['cheque_date'] ?? '');
    }

    private function normalisedPaymentRows(): array
    {
        return collect($this->paymentRows)
            ->map(function (array $row): array {
                $method = in_array($row['method'] ?? 'cash', ['cash', 'card', 'qr', 'bank_transfer', 'cheque'], true)
                    ? $row['method']
                    : 'cash';

                return [
                    'amount' => round(max(0, (float) ($row['amount'] ?? 0)), 2),
                    'method' => $method,
                    'reference' => (string) ($row['reference'] ?? ''),
                    'cheque_bank' => $method === 'cheque' ? (string) ($row['cheque_bank'] ?? '') : '',
                    'cheque_no' => $method === 'cheque' ? (string) ($row['cheque_no'] ?? '') : '',
                    'cheque_date' => $method === 'cheque' ? (string) ($row['cheque_date'] ?? '') : null,
                ];
            })
            ->filter(fn(array $row): bool => $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function paymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)->sum('amount'), 2);
    }

    private function capturedPaymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)
            ->whereIn('method', ['cash', 'card', 'qr', 'bank_transfer'])
            ->sum('amount'), 2);
    }

    private function chequePaymentRowsTotal(array $paymentRows): float
    {
        return round((float) collect($paymentRows)->where('method', 'cheque')->sum('amount'), 2);
    }

    private function syncReturnCreditSubtotal(string $key): void
    {
        if (! isset($this->returnCredits[$key])) {
            return;
        }

        $quantity = max(1, (int) $this->returnCredits[$key]['quantity']);
        $returnPrice = max(0, (float) $this->returnCredits[$key]['return_price']);

        $this->returnCredits[$key]['subtotal'] = round($quantity * $returnPrice, 2);
        $this->paid_amount = $this->cartTotal;
        $this->syncLegacyPaymentToFirstRowWhenOnlyOneRow();
    }

    private function returnCreditKey(int $saleId, int $productId): string
    {
        return $saleId . '-' . $productId;
    }

    public function maxReturnableQuantityForSaleItem(SaleItem $saleItem): int
    {
        $soldQuantity = (int) SaleItem::query()
            ->where('sale_id', $saleItem->sale_id)
            ->where('product_id', $saleItem->product_id)
            ->where('quantity', '>', 0)
            ->sum('quantity');

        $returnedQuantity = (int) SaleReturnItem::query()
            ->whereHas('returnLog', fn($query) => $query->where('sale_id', $saleItem->sale_id))
            ->where('product_id', $saleItem->product_id)
            ->sum('quantity');

        return max(0, $soldQuantity - $returnedQuantity);
    }

    private function recordCheckoutReturnCredits(Sale $checkoutSale): void
    {
        foreach (collect($this->returnCredits)->groupBy('sale_id') as $originalSaleId => $returnCredits) {
            $return = SaleReturn::query()->create([
                'sale_id' => (int) $originalSaleId,
                'customer_id' => $checkoutSale->customer_id,
                'invoice_no' => $this->nextReturnNumber(),
                'date' => $this->saleDate,
                'return_type' => 'bill_credit',
                'refund_amount' => 0,
                'adjusted_amount' => round((float) $returnCredits->sum('subtotal'), 2),
                'notes' => 'Return credited on checkout ' . $checkoutSale->invoice_no . '.',
            ]);

            foreach ($returnCredits as $returnCredit) {
                $return->items()->create([
                    'product_id' => $returnCredit['product_id'],
                    'quantity' => (int) $returnCredit['quantity'],
                    'refund_price' => (float) $returnCredit['return_price'],
                    'subtotal' => (float) $returnCredit['subtotal'],
                ]);
            }
        }
    }

    private function nextReturnNumber(): string
    {
        do {
            $returnNo = 'RET-' . now()->format('ymd') . '-' . random_int(100, 999);
        } while (SaleReturn::query()->where('invoice_no', $returnNo)->exists());

        return $returnNo;
    }

    #[Computed]
    public function cartEditorGross()
    {
        return max(1, $this->editQuantity) * max(0.00, (float) $this->editUnitPrice);
    }

    #[Computed]
    public function cartEditorDiscount()
    {
        $editDiscount = (float) $this->editDiscountValue;
        return $this->editDiscountType === 'percentage'
            ? $this->cartEditorGross * min(max(0.00, $editDiscount), 100.00) / 100.00
            : min(max(0.00, $editDiscount), $this->cartEditorGross);
    }

    #[Computed]
    public function cartEditorTotal()
    {
        return max(0.00, $this->cartEditorGross - $this->cartEditorDiscount);
    }

    #[Computed]
    public function completedSale()
    {
        return $this->completedSaleId ? Sale::query()->with(['customer', 'items.product', 'payments'])->findOrFail($this->completedSaleId) : null;
    }
}; ?>

<div
    class="min-h-[calc(100vh-2rem)] overflow-x-hidden rounded-[2rem] bg-white text-zinc-950"
    x-data="{
        mobCartOpen: $wire.entangle('cartDrawerOpen'),
        checkoutOpen: $wire.entangle('checkoutOpen'),
        successOpen: $wire.entangle('successOpen'),
        cartItemEditorOpen: $wire.entangle('cartItemEditorOpen'),
        customerCreateOpen: $wire.entangle('customerCreateOpen'),
        shareCopied: false,
        sharePreparing: false,
        sharePdfError: false,
        sharePdfFile: null,
        sharePdfUrl: null,
        init() {
            this.$watch('successOpen', (isOpen) => {
                if (isOpen) {
                    this.resetSharePdf();
                    this.$nextTick(() => setTimeout(() => this.prepareBillPdf(), 350));
                }
            });
        },
        playSuccessBeep() {
            if (!window.imranAudioCtx) {
                window.imranAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            let ctx = window.imranAudioCtx;
            if (ctx.state === 'suspended') {
                ctx.resume();
            }
            let osc = ctx.createOscillator();
            let gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.1, ctx.currentTime);
            osc.start();
            osc.stop(ctx.currentTime + 0.1);
        },
        resetSharePdf() {
            this.shareCopied = false;
            this.sharePreparing = false;
            this.sharePdfError = false;
            this.sharePdfFile = null;
            if (this.sharePdfUrl) {
                URL.revokeObjectURL(this.sharePdfUrl);
                this.sharePdfUrl = null;
            }
        },
        isAndroidApp() {
            return typeof window.ImranAndroid !== 'undefined';
        },
        money(value) {
            const amount = Number.parseFloat(value || 0);
            return amount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        printBill() {
            if (this.isAndroidApp() && typeof window.ImranAndroid.printPage === 'function') {
                window.ImranAndroid.printPage();
                return;
            }

            window.print();
        },
        async blobToBase64(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onloadend = () => resolve(String(reader.result || '').split(',')[1] || '');
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });
        },
        async sharePdfWithAndroid() {
            if (!this.sharePdfFile) {
                return false;
            }

            if (!this.isAndroidApp() || typeof window.ImranAndroid.sharePdf !== 'function') {
                return false;
            }

            const base64 = await this.blobToBase64(this.sharePdfFile);
            window.ImranAndroid.sharePdf(this.sharePdfFile.name, base64);
            return true;
        },
        async loadPdfScript(src, globalChecker) {
            if (globalChecker()) {
                return;
            }

            const existingScript = document.querySelector(`script[src='${src}']`);
            if (existingScript) {
                await new Promise((resolve, reject) => {
                    existingScript.addEventListener('load', resolve, { once: true });
                    existingScript.addEventListener('error', reject, { once: true });
                    setTimeout(resolve, 1200);
                });
                return;
            }

            await new Promise((resolve, reject) => {
                const script = document.createElement('script');
                const timeout = setTimeout(() => reject(new Error(`Timed out loading ${src}`)), 10000);
                script.src = src;
                script.onload = () => {
                    clearTimeout(timeout);
                    resolve();
                };
                script.onerror = () => {
                    clearTimeout(timeout);
                    reject(new Error(`Failed to load ${src}`));
                };
                document.head.appendChild(script);
            });
        },
        async prepareBillPdf() {
            if (this.sharePreparing || this.sharePdfFile) {
                return;
            }

            const invoiceNo = this.$refs.shareBillTitle?.innerText?.trim() || 'Invoice';
            const a4El = document.getElementById('a4-invoice-template');
            const isA4 = !!a4El;
            const templateId = isA4 ? 'a4-invoice-template' : 'thermal-receipt-template';
            const originalEl = document.getElementById(templateId);

            if (!originalEl) {
                this.sharePdfError = true;
                return;
            }

            this.sharePreparing = true;
            this.sharePdfError = false;

            try {
                await this.loadPdfScript(
                    '/vendor/pos-share/html2canvas-pro.min.js',
                    () => typeof window.html2canvas !== 'undefined'
                );

                await this.loadPdfScript(
                    '/vendor/pos-share/jspdf.umd.min.js',
                    () => typeof window.jsPDF !== 'undefined' || !!window.jspdf?.jsPDF
                );

                if (window.jspdf?.jsPDF && typeof window.jsPDF === 'undefined') {
                    window.jsPDF = window.jspdf.jsPDF;
                }

                if (typeof window.html2canvas === 'undefined' || typeof window.jsPDF === 'undefined') {
                    throw new Error('PDF generator libraries are unavailable.');
                }

                const wrapper = document.createElement('div');
                wrapper.dataset.pdfShareWrapper = 'true';
                wrapper.style.position = 'fixed';
                wrapper.style.left = '0';
                wrapper.style.top = '0';
                wrapper.style.width = isA4 ? '794px' : '300px';
                wrapper.style.minHeight = isA4 ? '1123px' : 'auto';
                wrapper.style.zIndex = '-99999';
                wrapper.style.pointerEvents = 'none';
                wrapper.style.background = '#ffffff';
                wrapper.style.overflow = 'visible';

                const clone = originalEl.cloneNode(true);
                clone.style.display = 'block';
                clone.style.visibility = 'visible';
                clone.classList.remove('hidden');
                clone.classList.remove('print:block');
                clone.style.position = 'static';
                clone.style.width = isA4 ? '794px' : '100%';
                clone.style.height = 'auto';
                clone.style.minHeight = isA4 ? '1123px' : 'auto';
                clone.style.padding = isA4 ? '0' : '12px';
                clone.style.background = '#ffffff';
                clone.style.color = '#000000';

                if (isA4 && clone.firstElementChild) {
                    clone.firstElementChild.style.height = 'auto';
                    clone.firstElementChild.style.minHeight = '1123px';
                    clone.firstElementChild.style.overflow = 'visible';
                }

                wrapper.appendChild(clone);
                document.body.appendChild(wrapper);

                const canvas = await window.html2canvas(clone, {
                    scale: this.isAndroidApp() ? 1 : 1.5,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                });

                wrapper.remove();

                const imgData = canvas.toDataURL('image/jpeg', this.isAndroidApp() ? 0.88 : 0.98);
                const imgWidth = isA4 ? 210 : 80;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                const pdf = new window.jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: isA4 ? 'a4' : [80, imgHeight]
                });

                const pageHeight = pdf.internal.pageSize.getHeight();
                let remainingHeight = imgHeight;
                let imageTop = 0;

                pdf.addImage(imgData, 'JPEG', 0, imageTop, imgWidth, imgHeight);
                remainingHeight -= pageHeight;

                while (remainingHeight > 1) {
                    imageTop -= pageHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'JPEG', 0, imageTop, imgWidth, imgHeight);
                    remainingHeight -= pageHeight;
                }

                const blob = pdf.output('blob');

                this.sharePdfFile = new File([blob], `${invoiceNo}.pdf`, { type: 'application/pdf' });
                this.sharePdfUrl = URL.createObjectURL(this.sharePdfFile);
            } catch (err) {
                console.error('PDF generation error:', err);
                this.sharePdfError = true;
            } finally {
                document.querySelectorAll('[data-pdf-share-wrapper]').forEach((el) => el.remove());
                this.sharePreparing = false;
            }
        },
        downloadPdfFile() {
            if (!this.sharePdfFile) {
                return;
            }

            if (this.isAndroidApp() && typeof window.ImranAndroid.downloadPdf === 'function') {
                this.blobToBase64(this.sharePdfFile).then(base64 => {
                    window.ImranAndroid.downloadPdf(this.sharePdfFile.name, base64);
                });
                return;
            }

            const link = document.createElement('a');
            link.href = this.sharePdfUrl || URL.createObjectURL(this.sharePdfFile);
            link.download = this.sharePdfFile.name;
            document.body.appendChild(link);
            link.click();
            link.remove();
        },
        async shareBill() {
            this.shareCopied = false;

            if (!this.sharePdfFile) {
                await this.prepareBillPdf();
            }

            if (!this.sharePdfFile) {
                alert('PDF file is not ready yet. Please try again in a moment.');
                return;
            }

            try {
                if (await this.sharePdfWithAndroid()) {
                    this.shareCopied = true;
                    setTimeout(() => this.shareCopied = false, 1800);
                } else if (navigator.share && navigator.canShare?.({ files: [this.sharePdfFile] })) {
                    await navigator.share({
                        files: [this.sharePdfFile],
                        title: this.sharePdfFile.name.replace('.pdf', ''),
                    });
                } else {
                    this.downloadPdfFile();
                }

                this.shareCopied = true;
                setTimeout(() => this.shareCopied = false, 1800);
            } catch (err) {
                if (err?.name !== 'AbortError') {
                    console.error('PDF sharing error:', err);
                    this.downloadPdfFile();
                    this.shareCopied = true;
                    setTimeout(() => this.shareCopied = false, 1800);
                }
            }
        }
    }"
    x-effect="if (successOpen && !sharePreparing && !sharePdfFile && !sharePdfError) { $nextTick(() => setTimeout(() => prepareBillPdf(), 250)) }"
    x-on:play-beep.window="playSuccessBeep()">
    <!-- Hidden barcode input form for active scanner tracking -->
    <form wire:submit.prevent="handleBarcodeInput" class="sr-only">
        <input
            wire:model="barcodeInput"
            id="hidden-barcode-scanner"
            type="text"
            placeholder="Active Scanner Target" />
    </form>

    <!-- POS command header -->
    <header class="sticky top-3 z-30 rounded-[1.75rem] border border-zinc-200/70 bg-white/90 p-3 shadow-[0_18px_55px_rgba(15,23,42,0.08)] backdrop-blur-xl">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-3">
                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-zinc-950 text-white shadow-[0_14px_28px_rgba(15,23,42,0.22)]">
                    <flux:icon.shopping-bag class="size-5" />
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-600">Imran POS</p>
                    <h1 class="font-display text-xl font-bold tracking-tight text-zinc-950">{{ __('Checkout register') }}</h1>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 sm:flex sm:items-center">
                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white px-3 py-2 text-xs font-black text-zinc-700 shadow-sm transition active:scale-95">
                    <flux:icon.home class="size-4 text-violet-500" />
                    {{ __('Home') }}
                </a>
                <a href="{{ route('products.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white px-3 py-2 text-xs font-black text-zinc-700 shadow-sm transition active:scale-95">
                    <flux:icon.plus class="size-4 text-emerald-500" />
                    {{ __('Product') }}
                </a>
                <button type="button" @click="mobCartOpen = true" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-3 py-2 text-xs font-black text-white shadow-[0_14px_30px_rgba(15,23,42,0.22)] transition active:scale-95">
                    <flux:icon.shopping-cart class="size-4" />
                    {{ count($cart) }}
                </button>
            </div>
        </div>
    </header>



    <div class="mt-4 rounded-[1.75rem] border border-violet-100 bg-white p-4 shadow-[0_16px_45px_rgba(15,23,42,0.08)] lg:hidden">
        <div class="mb-2 flex items-center justify-between gap-3">
            <span class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Sale Customer') }}</span>
            <button type="button" wire:click="openQuickCustomerModal" class="inline-flex h-9 items-center gap-1.5 rounded-full bg-violet-50 px-3 text-xs font-black text-violet-700 ring-1 ring-violet-100 transition active:scale-95">
                <flux:icon.plus class="size-4" />
                {{ __('New') }}
            </button>
        </div>

        <div class="grid gap-2">
            <flux:input wire:model.live.debounce.250ms="customerSearch" placeholder="Search customer..." />

            <flux:select wire:model.live="customer_id" required>
                <option value="">{{ __('-- Select the customer --') }}</option>
                @foreach ($this->customers as $cust)
                <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?: 'Walk-in' }})</option>
                @endforeach
            </flux:select>
        </div>

        <div class="mt-2 flex items-center justify-between gap-3 rounded-2xl bg-zinc-50 px-3 py-2 text-xs">
            <span class="font-bold text-zinc-500">{{ __('Previous Due') }}</span>
            <span @class([ 'font-black' , 'text-rose-600'=> (float) ($this->selectedCustomer?->due_balance ?? 0) > 0,
                'text-emerald-600' => (float) ($this->selectedCustomer?->due_balance ?? 0) <= 0,
                    ])>
                    Rs {{ number_format((float) ($this->selectedCustomer?->due_balance ?? 0), 2) }}
            </span>
        </div>
    </div>

    <div class="mt-4 mb-3 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_390px]">
        <div class="hidden lg:block"></div>

        <div class="flex items-center justify-between gap-3 rounded-3xl border border-violet-200 bg-violet-50 px-4 py-3 shadow-[0_12px_35px_rgba(124,58,237,0.08)]">
            <span class="flex items-center gap-1.5 text-xs font-black text-violet-800">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-violet-600"></span>
                </span>
                {{ __('Holds Queue: ') }} <span class="font-bold">{{ count($heldOrders) }}</span>
            </span>

            @if (count($heldOrders) > 0)
            <select @change="$wire.resumeHeldOrder($event.target.value); $event.target.value = ''" class="max-w-[100px] cursor-pointer bg-transparent text-xs font-bold text-violet-900 hover:underline focus:outline-none">
                <option value="">Resume</option>
                @foreach ($heldOrders as $held)
                <option value="{{ $held['id'] }}">{{ $held['hold_no'] }}</option>
                @endforeach
            </select>
            @endif
        </div>
    </div>

    <!-- Grid POS Workspace -->
    <div class="grid min-h-0 min-w-0 flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_390px]">
        <!-- 1. Left Column: Product Catalog -->
        <div class="min-w-0">
            <livewire:pos.product-catalog wire:model.live="saleDate" />
        </div>

        <!-- 2. Right Column: Desktop Cart Panel (Hidden on Mobile) -->
        <div class="hidden min-h-0 min-w-0 flex-col rounded-[2rem] border border-zinc-200 bg-white p-5 shadow-[0_22px_70px_rgba(15,23,42,0.10)] lg:flex lg:max-h-[calc(100vh-14rem)]">
            <div class="flex items-center justify-between border-b border-zinc-100 pb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Current sale') }}</p>
                    <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Selected cart') }}</h3>
                </div>
                <button type="button" wire:click="resetCart" class="rounded-full px-3 py-1 text-xs font-black text-zinc-400 transition hover:bg-zinc-50 hover:text-zinc-700">Clear</button>
            </div>

            <div class="mt-4 rounded-3xl border border-violet-100 bg-violet-50/50 p-3">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Customer') }}</span>
                    <button type="button" wire:click="openQuickCustomerModal" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-[10px] font-black text-violet-700 ring-1 ring-violet-100 transition active:scale-95">
                        <flux:icon.plus class="size-3" />
                        {{ __('New') }}
                    </button>
                </div>

                <flux:input wire:model.live.debounce.250ms="customerSearch" placeholder="Search customer..." />

                <div class="mt-2">
                    <flux:select wire:model.live="customer_id" required>
                        <option value="">{{ __('-- Select the customer --') }}</option>
                        @foreach ($this->customers as $cust)
                        <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?: 'Walk-in' }})</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="mt-2 flex items-center justify-between gap-3 rounded-2xl bg-white px-3 py-2 text-xs">
                    <span class="font-bold text-zinc-500">{{ __('Previous Due') }}</span>
                    <span @class([ 'font-black' , 'text-rose-600'=> (float) ($this->selectedCustomer?->due_balance ?? 0) > 0,
                        'text-emerald-600' => (float) ($this->selectedCustomer?->due_balance ?? 0) <= 0,
                            ])>
                            Rs {{ number_format((float) ($this->selectedCustomer?->due_balance ?? 0), 2) }}
                    </span>
                </div>
            </div>

            <!-- Cart rows -->
            <div class="flex-1 overflow-y-auto scrollbar-none py-4 flex flex-col gap-3">
                @forelse ($cart as $index => $item)
                <div class="flex flex-col gap-3 rounded-3xl border border-violet-100 bg-violet-50/40 p-3 shadow-sm" wire:key="desktop-cart-{{ $index }}">
                    <div class="flex justify-between items-start">
                        <div>
                            <button type="button" wire:click="openCartItemEditor({{ $index }})" class="line-clamp-1 text-left text-sm font-black text-zinc-950 underline-offset-4 transition hover:text-violet-700 hover:underline">
                                {{ $item['name'] }}
                            </button>
                            <span class="text-[9px] text-zinc-400 uppercase font-mono mt-0.5">SKU: {{ $item['sku'] }}</span>
                            @if (($item['discount_value'] ?? 0) > 0)
                            <span class="mt-1 block text-[9px] font-bold uppercase tracking-wide text-emerald-600">
                                {{ __('Discount') }}:
                                {{ ($item['discount_type'] ?? 'fixed') === 'percentage' ? number_format($item['discount_value'], 2) . '%' : 'Rs ' . number_format($item['discount_value'], 2) }}
                            </span>
                            @endif
                        </div>
                        <button type="button" wire:click="removeCartRow({{ $index }})" class="grid h-8 w-8 place-items-center rounded-xl bg-white text-rose-500 shadow-sm transition active:scale-90">
                            <flux:icon.trash class="size-4" />
                        </button>
                    </div>

                    <!-- Row control and details -->
                    <div class="flex items-center justify-between border-t border-zinc-100 pt-2 text-xs">
                        <div class="flex items-center gap-1 bg-white rounded-xl border border-zinc-200 p-0.5">
                            <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] - 1 }})" class="size-6 rounded-lg hover:bg-zinc-100 flex items-center justify-center font-bold text-zinc-600" aria-label="{{ __('Decrease quantity') }}">-</button>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                value="{{ $item['quantity'] }}"
                                @if (! $allowNegativeStock) max="{{ $item['stock'] }}" @endif
                                wire:change="updateCartQty({{ $index }}, $event.target.value)"
                                class="h-6 w-10 appearance-none bg-transparent text-center text-xs font-bold text-zinc-900 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                aria-label="{{ __('Quantity') }}" />
                            <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] + 1 }})" class="size-6 rounded-lg hover:bg-zinc-100 flex items-center justify-center font-bold text-zinc-600" aria-label="{{ __('Increase quantity') }}">+</button>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <!-- Price toggle wholesale / retail -->
                            <button
                                type="button"
                                class="rounded-lg px-1.5 py-0.5 text-[9px] font-bold border transition"
                                :class="@js($item['price_type']) === 'wholesale' ? 'bg-[#E0ECFF] text-blue-700 border-[#B6CFF7]' : 'bg-transparent text-zinc-400 border-zinc-200'"
                                wire:click="togglePriceType({{ $index }})">
                                {{ ($item['price_type'] ?? 'retail') === 'custom' ? 'Custom' : 'WS' }}
                            </button>
                            <span class="font-bold text-zinc-950">Rs {{ number_format($item['subtotal'], 2) }}</span>
                        </div>
                    </div>
                </div>
                @empty
                <div class="flex h-full flex-col items-center justify-center rounded-3xl bg-zinc-50 px-6 py-14 text-center text-sm text-zinc-400">
                    <flux:icon.shopping-cart class="mb-3 size-10 text-zinc-300" />
                    <p class="font-bold">{{ __('Cart is empty') }}</p>
                    <p class="mt-1 text-xs">{{ __('Tap product cards to build the customer bill.') }}</p>
                </div>
                @endforelse
            </div>

            <!-- Cart Calculations and Checkout trigger -->
            <div class="border-t border-zinc-100 pt-4 flex flex-col gap-3">
                <div class="flex justify-between rounded-3xl bg-zinc-50 px-4 py-3 text-sm">
                    <span class="text-zinc-500">Cart Total</span>
                    <span class="text-lg font-black text-zinc-950">Rs {{ number_format($this->cartTotal, 2) }}</span>
                </div>

                <div class="flex gap-2">
                    <flux:button type="button" wire:click="holdOrder" variant="ghost" class="flex-1">
                        {{ __('Hold') }}
                    </flux:button>
                    <flux:button type="button" wire:click="$set('checkoutOpen', true)" variant="primary" class="flex-1 bg-zinc-950!" :disabled="count($cart) === 0">
                        {{ __('Checkout') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. MOBILE FLOATING CART BUTTON CHIP (Only on mobile when cart > 0) -->
    @if (count($cart) > 0)
    <div class="fixed bottom-16 right-4 z-40 lg:hidden">
        <button
            type="button"
            class="flex items-center gap-2 rounded-full bg-zinc-950 px-5 py-3 font-bold text-white shadow-xl shadow-zinc-300 transition transform hover:scale-105 active:scale-95"
            @click="mobCartOpen = true">
            <flux:icon.shopping-cart class="size-5" />
            <span class="text-xs">{{ count($cart) }} {{ __('items') }}</span>
            <span class="text-xs font-semibold opacity-70">|</span>
            <span class="text-xs">Rs {{ number_format($this->cartTotal, 0) }}</span>
        </button>
    </div>
    @endif

    <div class="fixed bottom-3 left-3 right-3 z-40 lg:hidden">
        <nav class="rounded-[1.75rem] border border-zinc-200 bg-white/95 px-2 py-2 shadow-[0_18px_55px_rgba(15,23,42,0.14)] backdrop-blur-xl">
            <div class="grid grid-cols-5 items-center text-[10px] font-bold text-zinc-400">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex flex-col items-center gap-1">
                    <flux:icon.home class="size-5" />
                    <span>{{ __('Home') }}</span>
                </a>
                <a href="{{ route('products.index') }}" wire:navigate class="flex flex-col items-center gap-1">
                    <flux:icon.cube class="size-5" />
                    <span>{{ __('Stock') }}</span>
                </a>
                <button type="button" @click="mobCartOpen = true" class="-mt-7 justify-self-center rounded-full border-4 border-white bg-violet-600 p-4 text-white shadow-[0_14px_30px_rgba(124,58,237,0.42)]">
                    <flux:icon.shopping-bag class="size-6" />
                </button>
                <a href="{{ route('reports.index') }}" wire:navigate class="flex flex-col items-center gap-1">
                    <flux:icon.chart-bar class="size-5" />
                    <span>{{ __('Reports') }}</span>
                </a>
                <button
                    type="button"
                    @click="checkoutOpen = true"
                    @disabled(count($cart)===0)
                    @class([ 'flex flex-col items-center gap-1 transition' , 'text-zinc-300'=> count($cart) === 0,
                    ])
                    >
                    <flux:icon.credit-card class="size-5" />
                    <span>{{ __('Pay') }}</span>
                </button>
            </div>
        </nav>
    </div>

    <!-- 4. MOBILE BOTTOM DRAWER CART SHEET -->
    <div
        x-cloak
        x-show="mobCartOpen"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm lg:hidden"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <div
            class="w-full max-h-[80%] bg-white rounded-t-3xl shadow-2xl flex flex-col overflow-hidden"
            @click.away="mobCartOpen = false"
            x-transition:enter="ease-out duration-300 transform translate-y-full"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="ease-in duration-200 transform translate-y-0"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full">
            <div class="flex items-center justify-between border-b border-zinc-100 p-4 bg-zinc-50/50">
                <h3 class="font-display font-bold text-sm text-zinc-950">{{ __('Checkout Cart') }}</h3>
                <flux:button variant="ghost" size="sm" @click="mobCartOpen = false">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <div class="border-b border-zinc-100 bg-white p-4">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Customer') }}</span>
                    <button type="button" wire:click="openQuickCustomerModal" class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2.5 py-1 text-[10px] font-black text-violet-700 ring-1 ring-violet-100 transition active:scale-95">
                        <flux:icon.plus class="size-3" />
                        {{ __('New') }}
                    </button>
                </div>

                <flux:input wire:model.live.debounce.250ms="customerSearch" placeholder="Search customer..." />

                <div class="mt-2">
                    <flux:select wire:model.live="customer_id" required>
                        <option value="">{{ __('-- Select the customer --') }}</option>
                        @foreach ($this->customers as $cust)
                        <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?: 'Walk-in' }})</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="mt-2 flex items-center justify-between gap-3 rounded-2xl bg-zinc-50 px-3 py-2 text-xs">
                    <span class="font-bold text-zinc-500">{{ __('Previous Due') }}</span>
                    <span @class([ 'font-black' , 'text-rose-600'=> (float) ($this->selectedCustomer?->due_balance ?? 0) > 0,
                        'text-emerald-600' => (float) ($this->selectedCustomer?->due_balance ?? 0) <= 0,
                            ])>
                            Rs {{ number_format((float) ($this->selectedCustomer?->due_balance ?? 0), 2) }}
                    </span>
                </div>
            </div>

            <!-- Cart list scroll -->
            <div class="flex-1 overflow-y-auto p-4 flex flex-col gap-3">
                @foreach ($cart as $index => $item)
                <div class="rounded-2xl border border-zinc-100 bg-zinc-50/30 p-3 flex flex-col gap-2" wire:key="mobile-cart-item-{{ $index }}">
                    <div class="flex justify-between items-start">
                        <div>
                            <button type="button" wire:click="openCartItemEditor({{ $index }})" class="text-left text-xs font-bold text-zinc-900 underline-offset-4 transition hover:text-violet-700 hover:underline">
                                {{ $item['name'] }}
                            </button>
                            <span class="text-[9px] text-zinc-400 uppercase font-mono mt-0.5">SKU: {{ $item['sku'] }}</span>
                            @if (($item['discount_value'] ?? 0) > 0)
                            <span class="mt-1 block text-[9px] font-bold uppercase tracking-wide text-emerald-600">
                                {{ __('Discount') }}:
                                {{ ($item['discount_type'] ?? 'fixed') === 'percentage' ? number_format($item['discount_value'], 2) . '%' : 'Rs ' . number_format($item['discount_value'], 2) }}
                            </span>
                            @endif
                        </div>
                        <button type="button" wire:click="removeCartRow({{ $index }})" class="text-xs font-semibold text-rose-500">
                            Remove
                        </button>
                    </div>

                    <div class="flex items-center justify-between border-t border-zinc-100 pt-2 text-xs">
                        <div class="flex items-center gap-1 bg-white rounded-xl border border-zinc-200 p-0.5">
                            <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] - 1 }})" class="size-6 rounded-lg flex items-center justify-center font-bold text-zinc-600" aria-label="{{ __('Decrease quantity') }}">-</button>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                value="{{ $item['quantity'] }}"
                                @if (! $allowNegativeStock) max="{{ $item['stock'] }}" @endif
                                wire:change="updateCartQty({{ $index }}, $event.target.value)"
                                class="h-6 w-10 appearance-none bg-transparent text-center text-xs font-bold text-zinc-900 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                aria-label="{{ __('Quantity') }}" />
                            <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] + 1 }})" class="size-6 rounded-lg flex items-center justify-center font-bold text-zinc-600" aria-label="{{ __('Increase quantity') }}">+</button>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg px-1.5 py-0.5 text-[9px] font-bold border transition"
                                :class="@js($item['price_type']) === 'wholesale' ? 'bg-[#E0ECFF] text-blue-700 border-[#B6CFF7]' : 'bg-transparent text-zinc-400 border-zinc-200'"
                                wire:click="togglePriceType({{ $index }})">
                                {{ ($item['price_type'] ?? 'retail') === 'custom' ? 'Custom' : 'Wholesale' }}
                            </button>
                            <span class="font-bold text-zinc-950">Rs {{ number_format($item['subtotal'], 2) }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <!-- Footer Calculations mobile -->
            <div class="border-t border-zinc-100 p-4 flex flex-col gap-3">
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Cart Total</span>
                    <span class="font-black text-lg text-orange-600">Rs {{ number_format($this->cartTotal, 2) }}</span>
                </div>

                <div class="flex gap-2">
                    <flux:button type="button" wire:click="holdOrder" variant="ghost" class="flex-1">
                        {{ __('Hold Cart') }}
                    </flux:button>
                    <flux:button type="button" wire:click="$set('checkoutOpen', true)" variant="primary" class="flex-1">
                        {{ __('Checkout') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. QUICK CUSTOMER CREATE POPUP -->
    <div
        x-cloak
        x-show="customerCreateOpen"
        class="fixed inset-0 z-[65] flex items-center justify-center bg-black/50 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <form
            wire:submit="saveQuickCustomer"
            class="w-full max-w-md rounded-[2rem] bg-white p-5 shadow-2xl"
            @click.away="$wire.closeQuickCustomerModal()"
            x-transition:enter="ease-out duration-200 transform"
            x-transition:enter-start="translate-y-4 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-4 scale-95">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('New customer') }}</p>
                    <h3 class="mt-1 text-lg font-black text-zinc-950">{{ __('Add customer to checkout') }}</h3>
                </div>
                <button type="button" wire:click="closeQuickCustomerModal" class="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-zinc-200 text-zinc-500 transition hover:bg-zinc-50">
                    <flux:icon.x-mark class="size-4" />
                </button>
            </div>

            <div class="mt-4 grid gap-3">
                <flux:input wire:model="quickCustomerName" :label="__('Customer Name')" required />
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="quickCustomerPhone" :label="__('Phone')" />
                    <flux:input wire:model="quickCustomerEmail" :label="__('Email')" type="email" />
                </div>
                <flux:textarea wire:model="quickCustomerAddress" :label="__('Address')" rows="2" />
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2">
                <flux:button type="button" wire:click="closeQuickCustomerModal" variant="ghost" class="w-full">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="w-full bg-zinc-950!">
                    {{ __('Add Customer') }}
                </flux:button>
            </div>
        </form>
    </div>

    <!-- 5. CART ITEM EDITOR POPUP -->
    <div
        x-cloak
        x-show="cartItemEditorOpen"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <form
            x-data="{
                qty: Number($wire.editQuantity) || 1,
                unit: Number($wire.editUnitPrice) || 0,
                discountType: $wire.editDiscountType || 'fixed',
                discountValue: Number($wire.editDiscountValue) || 0,
                syncFromServer() {
                    this.qty = Number($wire.editQuantity) || 1;
                    this.unit = Number($wire.editUnitPrice) || 0;
                    this.discountType = $wire.editDiscountType || 'fixed';
                    this.discountValue = Number($wire.editDiscountValue) || 0;
                },
                get gross() {
                    return Math.max(1, Number(this.qty) || 1) * Math.max(0, Number(this.unit) || 0);
                },
                get discount() {
                    const value = Math.max(0, Number(this.discountValue) || 0);
                    return this.discountType === 'percentage'
                        ? this.gross * Math.min(value, 100) / 100
                        : Math.min(value, this.gross);
                },
                get total() {
                    return Math.max(0, this.gross - this.discount);
                },
                save() {
                    this.qty = Math.max(1, Number(this.qty) || 1);
                    this.unit = Math.max(0, Number(this.unit) || 0);
                    this.discountValue = Math.max(0, Number(this.discountValue) || 0);
                    $wire.saveCartItemEditorFromPayload(this.qty, this.unit, this.discountType, this.discountValue);
                }
            }"
            x-effect="if (cartItemEditorOpen) syncFromServer()"
            @submit.prevent="save()"
            class="w-full max-w-md rounded-[2rem] bg-white p-5 shadow-2xl"
            @click.away="$wire.closeCartItemEditor()"
            x-transition:enter="ease-out duration-200 transform"
            x-transition:enter-start="translate-y-4 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-4 scale-95">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-4">
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Edit cart item') }}</p>
                    <h3 class="mt-1 line-clamp-2 text-lg font-black text-zinc-950">{{ $editCartName }}</h3>
                </div>
                <button type="button" wire:click="closeCartItemEditor" class="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-zinc-200 text-zinc-500 transition hover:bg-zinc-50">
                    <flux:icon.x-mark class="size-4" />
                </button>
            </div>

            <div class="mt-4 grid gap-3">
                <div class="grid grid-cols-2 gap-3">
                    <flux:input x-model.number="qty" :label="__('Quantity')" type="number" min="1" step="1" required inputmode="numeric" />
                    <flux:input x-model.number="unit" :label="__('Unit Price (Rs)')" type="number" min="0" step="0.01" required inputmode="decimal" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:select x-model="discountType" :label="__('Discount Type')">
                        <option value="fixed">{{ __('Fixed') }}</option>
                        <option value="percentage">{{ __('Percentage') }}</option>
                    </flux:select>
                    <flux:input x-model.number="discountValue" :label="__('Discount')" type="number" min="0" step="0.01" inputmode="decimal" />
                </div>

                <div class="rounded-3xl border border-zinc-100 bg-zinc-50 p-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">{{ __('Gross') }}</span>
                        <span class="font-bold text-zinc-900" x-text="`Rs ${money(gross)}`">Rs {{ number_format($this->cartEditorGross, 2) }}</span>
                    </div>
                    <div class="mt-2 flex justify-between gap-3">
                        <span class="text-zinc-500">{{ __('Item Discount') }}</span>
                        <span class="font-bold text-emerald-600" x-text="`- Rs ${money(discount)}`">- Rs {{ number_format($this->cartEditorDiscount, 2) }}</span>
                    </div>
                    <div class="mt-3 flex justify-between gap-3 border-t border-zinc-200 pt-3">
                        <span class="font-black text-zinc-950">{{ __('Line Total') }}</span>
                        <span class="text-lg font-black text-orange-600" x-text="`Rs ${money(total)}`">Rs {{ number_format($this->cartEditorTotal, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2">
                <flux:button type="button" wire:click="closeCartItemEditor" variant="ghost" class="w-full">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="w-full bg-zinc-950!">
                    {{ __('Save Item') }}
                </flux:button>
            </div>
        </form>
    </div>

    <!-- 6. SLIDE-OVER CHECKOUT DRAWER -->
    <div
        x-cloak
        x-show="checkoutOpen"
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-3 transition-opacity backdrop-blur-sm sm:items-center sm:p-4"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <div
            class="flex max-h-[95%] w-full max-w-md flex-col overflow-hidden rounded-3xl bg-white shadow-2xl sm:max-h-[90vh] lg:max-w-xl"
            @click.away="checkoutOpen = false"
            x-transition:enter="ease-out duration-300 transform"
            x-transition:enter-start="translate-y-6 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-200 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-6 scale-95">
            <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                <h3 class="font-display font-bold text-zinc-950">{{ __('Checkout Terminal') }}</h3>
                <flux:button variant="ghost" size="sm" wire:click="$set('checkoutOpen', false)">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <!-- Checkout Form Scroll View -->
            <form wire:submit="submitCheckout" class="scrollbar-none flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
                <div class="rounded-3xl border border-zinc-100 bg-white p-3 shadow-sm">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <span class="text-xs font-black uppercase tracking-wider text-zinc-400">{{ __('Customer Profile') }}</span>
                        <button type="button" wire:click="openQuickCustomerModal" class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-3 py-1.5 text-xs font-black text-violet-700 ring-1 ring-violet-100 transition active:scale-95">
                            <flux:icon.plus class="size-3.5" />
                            {{ __('New') }}
                        </button>
                    </div>

                    <flux:input wire:model.live.debounce.250ms="customerSearch" placeholder="Search customer name, phone, email..." />

                    <div class="mt-3">
                        <flux:select wire:model.live="customer_id" required>
                            <option value="">{{ __('-- Select the customer --') }}</option>
                            @foreach ($this->customers as $cust)
                            <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?: 'Walk-in' }})</option>
                            @endforeach
                        </flux:select>
                        @if ($this->selectedCustomer)
                        <p class="mt-2 text-xs font-bold text-zinc-700">
                            {{ __('Selected') }}: {{ $this->selectedCustomer->name }}
                        </p>
                        @endif
                    </div>
                </div>

                <!-- Calculations subtotal -->
                <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4 flex flex-col gap-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Cart Items Total</span>
                        <span class="font-semibold text-zinc-900">Rs {{ number_format($this->cartSubtotal, 2) }}</span>
                    </div>

                    <div class="grid gap-2 grid-cols-2 mt-1">
                        <flux:select wire:model.live="discount_type" :label="__('Discount Type')">
                            <option value="fixed">Flat (Rs)</option>
                            <option value="percentage">Percentage (%)</option>
                        </flux:select>
                        <flux:input wire:model.live="discount" :label="$discount_type === 'percentage' ? __('Discount (%)') : __('Discount Value')" type="number" step="0.01" />
                    </div>
                    <div class="mt-1">
                        <flux:input wire:model.live="tax" :label="__('Tax Amount (Rs)')" type="number" step="0.01" />
                    </div>

                    <div class="flex justify-between border-t border-zinc-100 pt-2 text-sm">
                        <span class="font-bold text-zinc-950">Net Grand Total</span>
                        <span class="font-extrabold text-orange-600">Rs {{ number_format($this->cartTotal, 2) }}</span>
                    </div>
                </div>

                <div class="rounded-3xl border border-rose-100 bg-rose-50/50 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-wider text-rose-500">{{ __('Return / Bill Credit') }}</h4>
                            <p class="mt-1 text-xs font-semibold leading-relaxed text-rose-700">
                                {{ __('Search products sold to the selected customer and subtract the returned value from this bill.') }}
                            </p>
                        </div>
                        <div class="shrink-0 rounded-2xl bg-white px-3 py-2 text-xs font-black text-rose-600 ring-1 ring-rose-100">
                            - Rs {{ number_format($this->returnCreditsTotal, 2) }}
                        </div>
                    </div>

                    @if (blank($customer_id))
                    <p class="mt-3 rounded-2xl bg-white px-3 py-2 text-xs font-bold text-zinc-500 ring-1 ring-zinc-100">
                        {{ __('Select the customer first to load their previous sold products.') }}
                    </p>
                    @else
                    <div class="mt-3">
                        <flux:input wire:model.live.debounce.250ms="returnSearch" placeholder="Search previous bill, product, or SKU..." />
                    </div>

                    @if (filled($returnSearch) && $this->returnCandidates->isNotEmpty())
                    <div class="mt-3 grid gap-2">
                        @foreach ($this->returnCandidates as $saleItem)
                        @php($returnableQty = $this->maxReturnableQuantityForSaleItem($saleItem))
                        <button
                            type="button"
                            wire:click="addReturnCredit({{ $saleItem->id }})"
                            class="rounded-2xl border border-rose-100 bg-white p-3 text-left shadow-sm transition active:scale-[0.99]"
                            wire:key="return-candidate-{{ $saleItem->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-black text-zinc-950">{{ $saleItem->product?->name }}</p>
                                    <p class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-400">
                                        {{ $saleItem->sale?->invoice_no }} · {{ $saleItem->sale?->date?->format('Y-m-d') }} · SKU {{ $saleItem->product?->sku ?: '-' }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-[10px] font-bold uppercase text-zinc-400">{{ __('Returnable') }}</p>
                                    <p class="text-sm font-black text-rose-600">{{ $returnableQty }}</p>
                                </div>
                            </div>
                        </button>
                        @endforeach
                    </div>
                    @elseif (filled($returnSearch))
                    <p class="mt-3 rounded-2xl bg-white px-3 py-2 text-xs font-bold text-zinc-500 ring-1 ring-zinc-100">
                        {{ __('No returnable sold products found for this customer.') }}
                    </p>
                    @endif
                    @endif

                    @error('returnCredits')
                    <p class="mt-3 rounded-2xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700">{{ $message }}</p>
                    @enderror

                    @if (count($returnCredits) > 0)
                    <div class="mt-3 grid gap-3">
                        @foreach ($returnCredits as $key => $returnCredit)
                        <div class="rounded-3xl border border-rose-100 bg-white p-4 shadow-sm" wire:key="return-credit-{{ $key }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-black text-zinc-950">{{ $returnCredit['name'] }}</p>
                                    <p class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-400">
                                        {{ $returnCredit['invoice_no'] }} · {{ __('Returnable') }} {{ $returnCredit['max'] }}
                                    </p>
                                </div>
                                <button type="button" wire:click="removeReturnCredit('{{ $key }}')" class="text-xs font-black text-rose-500 transition active:scale-95">
                                    {{ __('Remove') }}
                                </button>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <flux:input
                                    value="{{ $returnCredit['quantity'] }}"
                                    :label="__('Return Qty')"
                                    type="number"
                                    min="1"
                                    max="{{ $returnCredit['max'] }}"
                                    step="1"
                                    inputmode="numeric"
                                    wire:change="updateReturnCreditQty('{{ $key }}', $event.target.value)" />
                                <flux:input
                                    value="{{ $returnCredit['return_price'] }}"
                                    :label="__('Return Price (Rs)')"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    wire:change="updateReturnCreditPrice('{{ $key }}', $event.target.value)" />
                            </div>

                            <div class="mt-3 flex items-center justify-between rounded-2xl bg-rose-50 px-3 py-2 text-xs">
                                <span class="font-bold text-rose-700">{{ __('Bill Credit') }}</span>
                                <span class="font-black text-rose-700">- Rs {{ number_format((float) $returnCredit['subtotal'], 2) }}</span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>

                <!-- Payment details -->
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Record Payment Settlement') }}</h4>
                        <div class="grid grid-cols-2 gap-2 sm:flex">
                            <button type="button" wire:click="addPaymentRow('cash')" class="inline-flex items-center justify-center gap-1.5 rounded-2xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700 transition active:scale-95">
                                <flux:icon.banknotes class="size-4" />
                                {{ __('Cash') }}
                            </button>
                            <button type="button" wire:click="addPaymentRow('cheque')" class="inline-flex items-center justify-center gap-1.5 rounded-2xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-black text-amber-700 transition active:scale-95">
                                <flux:icon.plus class="size-4" />
                                {{ __('Cheque') }}
                            </button>
                        </div>
                    </div>

                    @error('paymentRows')
                    <p class="rounded-2xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700">{{ $message }}</p>
                    @enderror

                    <div class="grid gap-3">
                        @foreach ($paymentRows as $index => $paymentRow)
                        <div wire:key="checkout-payment-row-{{ $index }}" class="rounded-3xl border border-zinc-100 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-zinc-400">
                                        {{ __('Payment') }} #{{ $index + 1 }}
                                    </p>
                                    <p class="mt-0.5 text-xs font-semibold text-zinc-500">
                                        {{ __('Cash, card, QR, bank transfer, or cheque') }}
                                    </p>
                                </div>
                                @if (count($paymentRows) > 1)
                                <button type="button" wire:click="removePaymentRow({{ $index }})" class="text-xs font-black text-rose-500 transition active:scale-95">
                                    {{ __('Remove') }}
                                </button>
                                @endif
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <flux:input wire:model.live.number="paymentRows.{{ $index }}.amount" :label="__('Amount (Rs)')" type="number" min="0" step="0.01" required inputmode="decimal" />
                                <flux:select wire:model.live="paymentRows.{{ $index }}.method" :label="__('Payment Mode')">
                                    <option value="cash">{{ __('Cash Settlement') }}</option>
                                    <option value="card">{{ __('Business Card Swipe') }}</option>
                                    <option value="qr">{{ __('LankaQR scan') }}</option>
                                    <option value="bank_transfer">{{ __('Direct Bank Deposit') }}</option>
                                    <option value="cheque">{{ __('Cheque Payment Hold') }}</option>
                                </flux:select>
                            </div>

                            @if (($paymentRow['method'] ?? 'cash') === 'qr')
                            <div class="mt-3 flex flex-col items-center justify-center gap-2 rounded-2xl border border-zinc-100 bg-zinc-50/70 p-4">
                                <div class="flex size-28 items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-white shadow-sm">
                                    <flux:icon.qr-code class="size-20 text-zinc-900" />
                                </div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">LANKAQR MOCK SCAN</span>
                            </div>
                            @elseif (($paymentRow['method'] ?? 'cash') === 'cheque')
                            <div class="mt-3 rounded-3xl border border-amber-100 bg-amber-50/70 p-4">
                                <div class="mb-3 flex items-center gap-2 text-xs font-black uppercase tracking-wider text-amber-700">
                                    <flux:icon.banknotes class="size-4" />
                                    {{ __('Cheque details') }}
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:input wire:model="paymentRows.{{ $index }}.cheque_no" :label="__('Cheque No')" placeholder="Cheque number" />
                                    <flux:input wire:model="paymentRows.{{ $index }}.cheque_bank" :label="__('Bank (optional)')" placeholder="Bank name" />
                                    <div class="sm:col-span-2">
                                        <flux:input wire:model="paymentRows.{{ $index }}.cheque_date" :label="__('Cheque Date')" type="date" required />
                                    </div>
                                </div>
                                <p class="mt-3 text-xs font-semibold leading-relaxed text-amber-800">
                                    {{ __('Cheque payments stay as hold amount until marked passed. They do not increase customer due unless the payment split is short.') }}
                                </p>
                            </div>
                            @elseif ((float) ($paymentRow['amount'] ?? 0) > 0)
                            <div class="mt-3">
                                <flux:input wire:model="paymentRows.{{ $index }}.reference" :label="__('Payment Slip # / Transaction Reference')" placeholder="Receipt, slip, or TxID" />
                            </div>
                            @endif
                        </div>
                        @endforeach
                    </div>

                    <div class="grid gap-2 text-xs sm:grid-cols-3">
                        <div class="flex justify-between rounded-2xl border border-zinc-100 bg-zinc-50 p-4 sm:flex-col sm:gap-1">
                            <span class="font-semibold text-zinc-500">{{ __('Payment Total') }}</span>
                            <span class="font-extrabold text-zinc-950">Rs {{ number_format($this->paymentRowsTotalAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between rounded-2xl border border-amber-100 bg-amber-50/70 p-4 sm:flex-col sm:gap-1">
                            <span class="font-semibold text-amber-700">{{ __('Cheque Hold') }}</span>
                            <span class="font-extrabold text-amber-700">Rs {{ number_format($this->checkoutHoldPreview, 2) }}</span>
                        </div>
                        <div class="flex justify-between rounded-2xl border border-zinc-100 bg-zinc-50 p-4 sm:flex-col sm:gap-1">
                            <span class="font-semibold text-zinc-500">{{ __('Remaining Customer Due') }}</span>
                            <span class="font-extrabold text-rose-600">Rs {{ number_format($this->checkoutDuePreview, 2) }}</span>
                        </div>
                    </div>

                    <flux:textarea wire:model="notes" :label="__('Internal Invoice Notes')" rows="2" />
                </div>

                <flux:button type="submit" variant="primary" class="w-full mt-2">
                    {{ __('Complete Checkout Order') }}
                </flux:button>
            </form>
        </div>
    </div>

    <!-- 6. SUCCESS ACTIONS OVERLAY & ANIMATION -->
    <div
        x-cloak
        x-show="successOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">
        <div
            class="w-full max-w-sm bg-white rounded-3xl shadow-2xl p-6 text-center flex flex-col items-center gap-4"
            x-transition:enter="ease-out duration-300 transform scale-90"
            x-transition:enter-start="scale-90"
            x-transition:enter-end="scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="scale-100"
            x-transition:leave-end="scale-90">
            <!-- Animated Green Success Checkmark -->
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 border-2 border-emerald-400 animate-bounce">
                <flux:icon.check class="size-8 text-emerald-600" />
            </div>

            <div>
                <h3 class="font-display text-lg font-bold text-zinc-950">{{ __('Checkout Completed!') }}</h3>
                <p class="text-xs text-zinc-500 mt-1">Invoice Reference: <span x-ref="shareBillTitle" class="font-mono font-bold text-zinc-800">{{ $this->completedSale?->invoice_no }}</span></p>
            </div>

            <div class="w-full border-t border-zinc-100 pt-4 flex flex-col gap-2">
                <!-- Browser window print receipt trigger -->
                <flux:button type="button" @click="printBill()" variant="primary" class="w-full">
                    <flux:icon.printer class="size-4 mr-1" />
                    {{ Setting::get('invoice_paper_size', 'thermal_80mm') === 'A4' ? __('Print A4 Invoice') : __('Instant Thermal Receipt') }}
                </flux:button>

                <flux:button type="button" @click="shareBill()" x-bind:disabled="sharePreparing" variant="ghost" class="w-full border border-violet-100 bg-violet-50/70! text-violet-700!">
                    <flux:icon.share class="size-4 mr-1" />
                    <span x-text="sharePreparing ? 'Preparing PDF...' : (shareCopied ? 'PDF Shared / Downloaded' : (sharePdfError ? 'Retry PDF Share' : 'Share PDF Bill'))">{{ __('Share PDF Bill') }}</span>
                </flux:button>

                <!-- SMS confirmation trigger -->
                @if ($this->completedSale?->customer?->phone && $this->completedSale?->customer?->phone !== '0000000000')
                <flux:button type="button" wire:click="triggerSMSNotification" variant="ghost" class="w-full border-dashed">
                    <flux:icon.bolt class="size-4 mr-1 text-blue-600" />
                    {{ __('Resend Confirmation SMS') }}
                </flux:button>
                @endif

                <flux:button type="button" wire:click="closeSuccess" variant="ghost" class="w-full">
                    {{ __('Open Next Checkout') }}
                </flux:button>
            </div>
        </div>
    </div>

    @if ($this->completedSale)
    <pre x-ref="shareBillText" class="sr-only">IMRAN POS BILL
{{ Setting::get('business_name') }}
Invoice: {{ $this->completedSale->invoice_no }}
Date: {{ $this->completedSale->date->format('Y-m-d H:i') }}
Customer: {{ $this->completedSale->customer?->name }}
@if ($this->completedSale->customer?->phone)
Phone: {{ $this->completedSale->customer?->phone }}
@endif

Items:
@foreach ($this->completedSale->items as $item)
- {{ $item->product?->name }} x{{ $item->quantity }} @ Rs {{ number_format($item->selling_price, 2) }} = Rs {{ number_format($item->subtotal, 2) }}
@endforeach

Subtotal: Rs {{ number_format($this->completedSale->subtotal_amount, 2) }}
@if ($this->completedSale->discount_amount > 0)
Discount: - Rs {{ number_format($this->completedSale->discount_amount, 2) }}
@endif
Grand Total: Rs {{ number_format($this->completedSale->grand_total, 2) }}
Paid: Rs {{ number_format($this->completedSale->paid_amount, 2) }}
Due: Rs {{ number_format($this->completedSale->due_amount, 2) }}

{{ Setting::get('invoice_footer_note') }}</pre>
    <!-- 7. PRINT RECEIPT TEMPLATES -->
    <?php
    $invoicePaperSize = Setting::get('invoice_paper_size', 'thermal_80mm');
    $devName = trim((string) config('app.dev_name', ''));
    $thermalWidth = $invoicePaperSize === 'thermal_58mm' ? '58mm' : '80mm';
    $thermalPageSize = $invoicePaperSize === 'thermal_58mm' ? '58mm 210mm' : '80mm 297mm';
    $businessReceiptPhones = collect([Setting::get('business_phone'), Setting::get('business_phone_2')])
        ->filter(fn($value): bool => filled($value))
        ->implode(' / ');
    $businessBrNumber = Setting::get('business_br_number');
    $businessLogo = Setting::get('business_logo');
    $showBusinessLogo = Setting::get('invoice_show_logo', '1') !== '0';
    $businessLogoUrl = ($showBusinessLogo && $businessLogo) ? Storage::url($businessLogo) : null;
    ?>

    @if ($invoicePaperSize === 'A4')
    @include('partials.a4-invoice', ['sale' => $this->completedSale, 'devName' => $devName])
    @else
    <div id="thermal-receipt-template" class="hidden bg-white font-mono text-[10px] leading-tight text-black print:block" style="width: {{ $thermalWidth }}; max-width: {{ $thermalWidth }};">
        <style>
            @media print {
                @page {
                    size: {
                            {
                            $thermalPageSize
                        }
                    }

                    ;
                    margin: 0;
                }

                body * {
                    visibility: hidden !important;
                }

                #thermal-receipt-template,
                #thermal-receipt-template * {
                    visibility: visible !important;
                }

                #thermal-receipt-template {
                    position: fixed !important;
                    left: 0 !important;
                    top: 0 !important;

                    width: {
                            {
                            $thermalWidth
                        }
                    }

                    !important;

                    max-width: {
                            {
                            $thermalWidth
                        }
                    }

                    !important;
                    margin: 0 !important;
                    padding: 2mm !important;
                    background: white !important;
                    z-index: 9999999 !important;
                }
            }
        </style>

        <div class="text-center mb-3">
            @if ($businessLogoUrl)
            <div class="mx-auto mb-1 overflow-hidden bg-white" style="display: flex; width: 16mm !important; height: 12mm !important; align-items: center; justify-content: center;">
                <img src="{{ $businessLogoUrl }}" alt="{{ Setting::get('business_name') }}" style="display: block; width: 100% !important; height: 100% !important; max-width: 100% !important; max-height: 100% !important; object-fit: contain !important;">
            </div>
            @endif
            <h2 class="font-bold text-sm tracking-wide">{{ Setting::get('business_name') }}</h2>
            <p class="text-[9px] mt-0.5">{{ Setting::get('business_address') }}</p>
            @if ($businessReceiptPhones)
            <p class="text-[9px] mt-0.5">Tel: {{ $businessReceiptPhones }}</p>
            @endif
            @if ($businessBrNumber)
            <p class="text-[9px] mt-0.5">BR No: {{ $businessBrNumber }}</p>
            @endif
        </div>

        <div class="border-b border-dashed border-zinc-400 pb-2 mb-2">
            <p>Invoice: <span class="font-bold">{{ $this->completedSale->invoice_no }}</span></p>
            <p>Date: {{ $this->completedSale->date->format('Y-m-d H:i') }}</p>
            <p>Customer: {{ $this->completedSale->customer?->name }}</p>
            @if ($this->completedSale->customer?->phone)
            <p>Phone: {{ $this->completedSale->customer?->phone }}</p>
            @endif
        </div>

        <!-- Items Table -->
        <div class="border-b border-dashed border-zinc-400 pb-2 mb-2 flex flex-col gap-1.5">
            @foreach ($this->completedSale->items as $item)
            <div class="flex justify-between">
                <div>
                    <p class="font-bold">{{ $item->product?->name }}</p>
                    <p class="text-[9px] text-zinc-600">{{ $item->quantity }} x Rs {{ number_format($item->selling_price, 2) }}</p>
                </div>
                <p class="font-bold">Rs {{ number_format($item->subtotal, 2) }}</p>
            </div>
            @endforeach
        </div>

        <!-- Financials summary -->
        <div class="flex flex-col gap-1 text-right mb-4">
            <div class="flex justify-between">
                <span>Subtotal</span>
                <span>Rs {{ number_format($this->completedSale->subtotal_amount, 2) }}</span>
            </div>
            @if ($this->completedSale->discount_amount > 0)
            <div class="flex justify-between">
                <span>Discount</span>
                <span>- Rs {{ number_format($this->completedSale->discount_amount, 2) }}</span>
            </div>
            @endif
            <div class="flex justify-between font-bold text-xs border-t border-dashed border-zinc-400 pt-1">
                <span>Grand Total</span>
                <span>Rs {{ number_format($this->completedSale->grand_total, 2) }}</span>
            </div>
            <div class="flex justify-between text-zinc-700">
                <span>Amount Paid</span>
                <span>Rs {{ number_format($this->completedSale->paid_amount, 2) }}</span>
            </div>
            <div class="flex justify-between text-zinc-700">
                <span>Due Balance</span>
                <span>Rs {{ number_format($this->completedSale->due_amount, 2) }}</span>
            </div>
        </div>

        <div class="text-center text-[9px] leading-snug">
            <p class="font-semibold">{{ Setting::get('invoice_footer_note') }}</p>
            @if ($devName !== '')
            <p class="text-[8px] text-zinc-600 mt-2">Powered by {{ $devName }}</p>
            @endif
        </div>
    </div>
    @endif
    @endif

    @assets
    <script src="/vendor/pos-share/html2canvas-pro.min.js" defer></script>
    <script src="/vendor/pos-share/jspdf.umd.min.js" defer></script>
    @endassets
</div>