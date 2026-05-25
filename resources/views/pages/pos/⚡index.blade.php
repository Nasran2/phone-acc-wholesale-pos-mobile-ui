<?php

use App\Models\Customer;
use App\Models\HoldOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\ChequePaymentService;
use App\Services\SmsNotificationService;
use App\Services\TextItSmsService;
use Flux\Flux;
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
    public int $customer_id = 1; // Default to Walk-in Customer
    public $discount = 0.00;
    public string $discount_type = 'fixed'; // fixed, percentage
    public $tax = 0.00;
    public $paid_amount = 0.00;
    public string $payment_method = 'cash';
    public string $payment_reference = '';
    public string $cheque_bank = '';
    public string $cheque_no = '';
    public string $cheque_date = '';
    public string $notes = '';
    public string $customerSearch = '';
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

        if ($sale && $sale->exists) {
            $this->editingSale = $sale;
            $this->customer_id = $sale->customer_id;
            $this->discount = (float) $sale->discount_amount;
            $this->discount_type = 'fixed';
            $this->tax = (float) $sale->tax_amount;
            $this->notes = $sale->notes ?? '';
            
            // Add items to cart
            foreach ($sale->items as $item) {
                $product = $item->product;
                if ($product) {
                    $this->cart[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'cost_price' => (float) $item->cost_price,
                        'selling_price' => (float) $item->selling_price,
                        'retail_price' => (float) $product->selling_price,
                        'wholesale_price' => (float) ($product->wholesale_price ?? $product->selling_price),
                        'price_type' => 'custom',
                        'quantity' => $item->quantity,
                        'discount_type' => 'fixed',
                        'discount_value' => 0.00,
                        'subtotal' => (float) $item->subtotal,
                        'stock' => $product->stock_quantity,
                    ];
                }
            }
            $this->paid_amount = (float) $sale->paid_amount;
        } else {
            // Get default customer id from settings or find first
            $defaultCust = Customer::query()->where('name', 'Walk-in Customer')->first();
            if ($defaultCust) {
                $this->customer_id = $defaultCust->id;
            }
        }

        app(ChequePaymentService::class)->autoPassOverduePendingCheques();
        $this->loadHeldOrders();
    }

    public function loadHeldOrders(): void
    {
        $this->heldOrders = HoldOrder::query()
            ->with('customer')
            ->orderBy('id', 'desc')
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
            $this->dispatch('play-beep'); // Trigger audio beep in frontend
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
    }

    public function updateCartQty(int $index, int $qty): void
    {
        if (isset($this->cart[$index])) {
            $newQty = max(1, $qty);
            $stockQuantity = (int) ($this->cart[$index]['stock'] ?? 0);

            if ($newQty > $stockQuantity && ! $this->allowNegativeStock) {
                Flux::toast(variant: 'danger', text: __('Cannot exceed warehouse stock limits.'));
                return;
            }

            $this->cart[$index]['quantity'] = $newQty;
            $this->syncCartItemSubtotal($index);
            $this->paid_amount = $this->cartTotal;
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
        $this->closeCartItemEditor();
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
            'customer_id' => $this->customer_id,
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
        $this->customer_id = $hold->customer_id ?? 1;
        $this->cart = $hold->items_json;
        $this->discount = (float) $hold->discount;
        $this->tax = (float) $hold->tax;
        $this->paid_amount = $this->cartTotal;
        
        $hold->delete();
        $this->loadHeldOrders();
        
        Flux::toast(variant: 'success', text: __('Cart session resumed.'));
    }

    public function submitCheckout(SmsNotificationService $smsNotificationService): void
    {
        $rules = [
            'customer_id' => 'required|exists:customers,id',
            'cart' => 'required|array|min:1',
            'discount' => 'required|numeric|min:0',
            'tax' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,card,qr,bank_transfer,cheque',
            'payment_reference' => 'nullable|string|max:100',
        ];

        if ($this->payment_method === 'cheque') {
            $rules['paid_amount'] = 'required|numeric|min:0.01';
            $rules['cheque_bank'] = 'nullable|string|max:100';
            $rules['cheque_no'] = 'nullable|string|max:100';
            $rules['cheque_date'] = 'required|date';
        }

        $this->validate($rules);

        if ($this->payment_method === 'cheque') {
            $customer = \App\Models\Customer::query()->find($this->customer_id);
            if (! $customer || $customer->phone === '0000000000' || strtolower($customer->name) === 'walk-in customer') {
                $this->addError('customer_id', __('Cheque payments are not allowed for Walk-in Customer. Please select a registered customer.'));
                return;
            }
        }

        $subtotal = $this->cartSubtotal;
        $grandTotal = $this->cartTotal;
        $isChequePayment = $this->payment_method === 'cheque';
        $capturedPaidAmount = $isChequePayment ? 0.00 : min((float) $this->paid_amount, $grandTotal);
        $dueAmount = max(0.00, $grandTotal - $capturedPaidAmount);

        if (! $isChequePayment && $dueAmount > 0 && Setting::get('pos_allow_due_sale', '1') === '0') {
            Flux::toast(variant: 'danger', text: __('Due sales disabled in system settings. Full payment required.'));
            return;
        }

        $paymentStatus = $isChequePayment ? 'cheque_pending' : 'due';
        if (! $isChequePayment && $capturedPaidAmount >= $grandTotal) {
            $paymentStatus = 'paid';
        } elseif (! $isChequePayment && $capturedPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        // Compute total product costs to register net profits
        $totalCost = 0.00;
        foreach ($this->cart as $item) {
            $totalCost += $item['quantity'] * $item['cost_price'];
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

            $sale->items()->delete();
            $sale->payments()->delete();

            $sale->update([
                'customer_id' => $this->customer_id,
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
                'date' => date('Y-m-d'),
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

        // 3. Log cashier polymorphic payment
        if ((float) $this->paid_amount > 0) {
            $paymentAmount = min((float) $this->paid_amount, $grandTotal);
            $paymentPayload = [
                'amount' => $paymentAmount,
                'payment_method' => $this->payment_method,
                'date' => date('Y-m-d'),
                'reference' => $isChequePayment ? ($this->cheque_no ?: $this->payment_reference) : $this->payment_reference,
                'notes' => $isChequePayment ? 'POS cheque payment on hold until cleared.' : 'POS Terminal Sale Checkout.',
            ];

            if ($isChequePayment) {
                $paymentPayload = array_merge($paymentPayload, [
                    'cheque_bank' => $this->cheque_bank ?: null,
                    'cheque_no' => $this->cheque_no ?: null,
                    'cheque_date' => $this->cheque_date,
                    'cheque_status' => 'pending',
                ]);
            }

            $sale->payments()->create($paymentPayload);
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
        $this->reset('cart', 'discount', 'tax', 'paid_amount', 'payment_method', 'payment_reference', 'cheque_bank', 'cheque_no', 'cheque_date', 'notes', 'customerSearch');
        $defaultCust = Customer::query()->where('name', 'Walk-in Customer')->first();
        if ($defaultCust) {
            $this->customer_id = $defaultCust->id;
        }
    }

    public function closeSuccess(): void
    {
        $this->successOpen = false;
        $this->completedSaleId = null;
        $this->resetCart();
    }

    #[Computed]
    public function customers()
    {
        $search = trim($this->customerSearch);

        return Customer::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($customerQuery) use ($search) {
                    $customerQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderByRaw("name = 'Walk-in Customer' DESC")
            ->orderBy('name')
            ->limit(25)
            ->get();
    }


    #[Computed]
    public function cartSubtotal()
    {
        return array_reduce($this->cart, fn($carry, $item) => $carry + $item['subtotal'], 0.00);
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
        return ($this->cartSubtotal + (float) $this->tax) - $this->cartDiscountAmount;
    }

    #[Computed]
    public function checkoutDuePreview()
    {
        if ($this->payment_method === 'cheque') {
            return $this->cartTotal;
        }

        return max(0.00, $this->cartTotal - (float) $this->paid_amount);
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
            let ctx = new (window.AudioContext || window.webkitAudioContext)();
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
                wrapper.style.height = isA4 ? '1123px' : 'auto';
                wrapper.style.zIndex = '-99999';
                wrapper.style.pointerEvents = 'none';
                wrapper.style.background = '#ffffff';
                wrapper.style.overflow = 'hidden';

                const clone = originalEl.cloneNode(true);
                clone.style.display = 'block';
                clone.style.visibility = 'visible';
                clone.classList.remove('hidden');
                clone.classList.remove('print:block');
                clone.style.position = 'static';
                clone.style.width = isA4 ? '794px' : '100%';
                clone.style.height = isA4 ? '1123px' : 'auto';
                clone.style.padding = isA4 ? '0' : '12px';
                clone.style.background = '#ffffff';
                clone.style.color = '#000000';

                wrapper.appendChild(clone);
                document.body.appendChild(wrapper);

                const canvas = await window.html2canvas(clone, {
                    scale: 2.5,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                });

                wrapper.remove();

                const imgData = canvas.toDataURL('image/jpeg', 0.98);
                const imgWidth = isA4 ? 210 : 80;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                const pdf = new window.jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: isA4 ? 'a4' : [80, imgHeight]
                });

                pdf.addImage(imgData, 'JPEG', 0, 0, imgWidth, imgHeight);
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
                if (navigator.share && navigator.canShare?.({ files: [this.sharePdfFile] })) {
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
    x-on:play-beep.window="playSuccessBeep()"
>
    <!-- Hidden barcode input form for active scanner tracking -->
    <form wire:submit.prevent="handleBarcodeInput" class="sr-only">
        <input
            wire:model="barcodeInput"
            id="hidden-barcode-scanner"
            type="text"
            placeholder="Active Scanner Target"
        />
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
                @island(name: 'cart')
                    <button type="button" @click="mobCartOpen = true" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-3 py-2 text-xs font-black text-white shadow-[0_14px_30px_rgba(15,23,42,0.22)] transition active:scale-95">
                        <flux:icon.shopping-cart class="size-4" />
                        {{ count($cart) }}
                    </button>
                @endisland
            </div>
        </div>
    </header>



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
            <livewire:pos.product-catalog />
        </div>

        @island(name: 'cart')
        <!-- 2. Right Column: Desktop Cart Panel (Hidden on Mobile) -->
        <div class="hidden min-h-0 min-w-0 flex-col rounded-[2rem] border border-zinc-200 bg-white p-5 shadow-[0_22px_70px_rgba(15,23,42,0.10)] lg:flex lg:max-h-[calc(100vh-14rem)]">
            <div class="flex items-center justify-between border-b border-zinc-100 pb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Current sale') }}</p>
                    <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Selected cart') }}</h3>
                </div>
                <button type="button" wire:click="resetCart" class="rounded-full px-3 py-1 text-xs font-black text-zinc-400 transition hover:bg-zinc-50 hover:text-zinc-700">Clear</button>
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
                                <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] - 1 }})" class="size-5 rounded-lg hover:bg-zinc-100 flex items-center justify-center font-bold text-zinc-600">-</button>
                                <span class="px-2 font-bold text-zinc-900">{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] + 1 }})" class="size-5 rounded-lg hover:bg-zinc-100 flex items-center justify-center font-bold text-zinc-600">+</button>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <!-- Price toggle wholesale / retail -->
                                <button
                                    type="button"
                                    class="rounded-lg px-1.5 py-0.5 text-[9px] font-bold border transition"
                                    :class="@js($item['price_type']) === 'wholesale' ? 'bg-[#E0ECFF] text-blue-700 border-[#B6CFF7]' : 'bg-transparent text-zinc-400 border-zinc-200'"
                                    wire:click="togglePriceType({{ $index }})"
                                >
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
        @endisland
    </div>

    <!-- 3. MOBILE FLOATING CART BUTTON CHIP (Only on mobile when cart > 0) -->
    @island(name: 'cart')
    @if (count($cart) > 0)
        <div class="fixed bottom-16 right-4 z-40 lg:hidden">
            <button
                type="button"
                class="flex items-center gap-2 rounded-full bg-zinc-950 px-5 py-3 font-bold text-white shadow-xl shadow-zinc-300 transition transform hover:scale-105 active:scale-95"
                @click="mobCartOpen = true"
            >
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
                    @disabled(count($cart) === 0)
                    @class([
                        'flex flex-col items-center gap-1 transition',
                        'text-zinc-300' => count($cart) === 0,
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
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-h-[80%] bg-white rounded-t-3xl shadow-2xl flex flex-col overflow-hidden"
            @click.away="mobCartOpen = false"
            x-transition:enter="ease-out duration-300 transform translate-y-full"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="ease-in duration-200 transform translate-y-0"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
        >
            <div class="flex items-center justify-between border-b border-zinc-100 p-4 bg-zinc-50/50">
                <h3 class="font-display font-bold text-sm text-zinc-950">{{ __('Checkout Cart') }}</h3>
                <flux:button variant="ghost" size="sm" @click="mobCartOpen = false">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
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
                                <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] - 1 }})" class="size-5 rounded-lg flex items-center justify-center font-bold text-zinc-600">-</button>
                                <span class="px-2 font-bold text-zinc-900">{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="updateCartQty({{ $index }}, {{ $item['quantity'] + 1 }})" class="size-5 rounded-lg flex items-center justify-center font-bold text-zinc-600">+</button>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg px-1.5 py-0.5 text-[9px] font-bold border transition"
                                    :class="@js($item['price_type']) === 'wholesale' ? 'bg-[#E0ECFF] text-blue-700 border-[#B6CFF7]' : 'bg-transparent text-zinc-400 border-zinc-200'"
                                    wire:click="togglePriceType({{ $index }})"
                                >
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
    @endisland

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
        x-transition:leave-end="opacity-0"
    >
        <form
            wire:submit="saveQuickCustomer"
            class="w-full max-w-md rounded-[2rem] bg-white p-5 shadow-2xl"
            @click.away="$wire.closeQuickCustomerModal()"
            x-transition:enter="ease-out duration-200 transform"
            x-transition:enter-start="translate-y-4 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-4 scale-95"
        >
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
        x-transition:leave-end="opacity-0"
    >
        <form
            wire:submit="saveCartItemEditor"
            class="w-full max-w-md rounded-[2rem] bg-white p-5 shadow-2xl"
            @click.away="$wire.closeCartItemEditor()"
            x-transition:enter="ease-out duration-200 transform"
            x-transition:enter-start="translate-y-4 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-4 scale-95"
        >
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
                    <flux:input wire:model.live="editQuantity" :label="__('Quantity')" type="number" min="1" step="1" required />
                    <flux:input wire:model.live="editUnitPrice" :label="__('Unit Price (Rs)')" type="number" min="0" step="0.01" required />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model.live="editDiscountType" :label="__('Discount Type')">
                        <option value="fixed">{{ __('Fixed') }}</option>
                        <option value="percentage">{{ __('Percentage') }}</option>
                    </flux:select>
                    <flux:input wire:model.live="editDiscountValue" :label="__('Discount')" type="number" min="0" step="0.01" />
                </div>

                <div class="rounded-3xl border border-zinc-100 bg-zinc-50 p-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-zinc-500">{{ __('Gross') }}</span>
                        <span class="font-bold text-zinc-900">Rs {{ number_format($this->cartEditorGross, 2) }}</span>
                    </div>
                    <div class="mt-2 flex justify-between gap-3">
                        <span class="text-zinc-500">{{ __('Item Discount') }}</span>
                        <span class="font-bold text-emerald-600">- Rs {{ number_format($this->cartEditorDiscount, 2) }}</span>
                    </div>
                    <div class="mt-3 flex justify-between gap-3 border-t border-zinc-200 pt-3">
                        <span class="font-black text-zinc-950">{{ __('Line Total') }}</span>
                        <span class="text-lg font-black text-orange-600">Rs {{ number_format($this->cartEditorTotal, 2) }}</span>
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
        x-transition:leave-end="opacity-0"
    >
        <div
            class="flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-3xl bg-white shadow-2xl sm:max-h-[90vh] lg:max-w-xl"
            @click.away="checkoutOpen = false"
            x-transition:enter="ease-out duration-300 transform"
            x-transition:enter-start="translate-y-6 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-200 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-6 scale-95"
        >
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
                        <flux:select wire:model="customer_id" required>
                            @foreach ($this->customers as $cust)
                                <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?: 'Walk-in' }})</option>
                            @endforeach
                        </flux:select>
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

                <!-- Payment details -->
                <div class="flex flex-col gap-3">
                    <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Record Payment Settlement') }}</h4>
                    
                    <flux:input wire:model.live="paid_amount" :label="$payment_method === 'cheque' ? __('Cheque Amount (Rs)') : __('Amount Collected (Rs)')" type="number" step="0.01" required />

                    <flux:select wire:model.live="payment_method" :label="__('Payment Mode')">
                        <option value="cash">Cash Settlement</option>
                        <option value="card">Business Card Swipe</option>
                        <option value="qr">LankaQR scan</option>
                        <option value="bank_transfer">Direct Bank Deposit</option>
                        <option value="cheque">Cheque Payment Hold</option>
                    </flux:select>

                    <!-- LankaQR Scan Box Placeholder -->
                    @if ($payment_method === 'qr')
                    <div class="rounded-2xl border border-zinc-100 bg-zinc-50/50 p-4 flex flex-col items-center justify-center gap-2">
                        <div class="border border-dashed border-zinc-300 rounded-xl p-3 bg-white flex items-center justify-center size-32 shadow-sm">
                            <flux:icon.qr-code class="size-24 text-zinc-900" />
                        </div>
                        <span class="text-[10px] text-zinc-400 uppercase font-bold tracking-wider">LANKAQR MOCK SCAN</span>
                    </div>
                    @endif

                    @if ($payment_method === 'cheque')
                        <div class="rounded-3xl border border-amber-100 bg-amber-50/70 p-4">
                            <div class="mb-3 flex items-center gap-2 text-xs font-black uppercase tracking-wider text-amber-700">
                                <flux:icon.banknotes class="size-4" />
                                {{ __('Cheque details') }}
                            </div>
                            <div class="grid gap-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <flux:input wire:model="cheque_bank" :label="__('Bank (optional)')" placeholder="Bank name" />
                                    <flux:input wire:model="cheque_no" :label="__('Cheque No (optional)')" placeholder="Cheque number" />
                                </div>
                                <flux:input wire:model="cheque_date" :label="__('Cheque Date')" type="date" required />
                            </div>
                            <p class="mt-3 text-xs font-semibold leading-relaxed text-amber-800">
                                {{ __('Cheque payments stay on hold until marked passed. If still pending 7 days after the cheque date, the system marks it passed automatically when POS opens.') }}
                            </p>
                        </div>
                    @endif

                    @if ($paid_amount > 0 && $payment_method !== 'cheque')
                        <flux:input wire:model="payment_reference" :label="__('Payment Slip # / Transaction Reference')" placeholder="cheque / TxID" />
                    @endif

                    <div class="flex justify-between text-xs rounded-2xl bg-zinc-50 border border-zinc-100 p-4">
                        <span class="text-zinc-500 font-semibold">Remaining Customer Account Due</span>
                        <span class="font-extrabold text-rose-600">Rs {{ number_format($this->checkoutDuePreview, 2) }}</span>
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
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-sm bg-white rounded-3xl shadow-2xl p-6 text-center flex flex-col items-center gap-4"
            x-transition:enter="ease-out duration-300 transform scale-90"
            x-transition:enter-start="scale-90"
            x-transition:enter-end="scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="scale-100"
            x-transition:leave-end="scale-90"
        >
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
                <flux:button type="button" @click="window.print()" variant="primary" class="w-full">
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
        ?>

        @if ($invoicePaperSize === 'A4')
            @include('partials.a4-invoice', ['sale' => $this->completedSale, 'devName' => $devName])
        @else
            <div id="thermal-receipt-template" class="hidden print:block bg-white p-3 font-mono text-[10px] leading-tight text-black max-w-[80mm] mx-auto">
                <style>
                    @media print {
                        body * {
                            visibility: hidden !important;
                        }
                        #thermal-receipt-template, #thermal-receipt-template * {
                            visibility: visible !important;
                        }
                        #thermal-receipt-template {
                            position: fixed !important;
                            left: 0 !important;
                            top: 0 !important;
                            width: 100% !important;
                            margin: 0 !important;
                            padding: 10px !important;
                            background: white !important;
                            z-index: 9999999 !important;
                        }
                    }
                </style>

                <div class="text-center mb-3">
                    <h2 class="font-bold text-sm tracking-wide">{{ Setting::get('business_name') }}</h2>
                    <p class="text-[9px] mt-0.5">{{ Setting::get('business_address') }}</p>
                    <p class="text-[9px] mt-0.5">Tel: {{ Setting::get('business_phone') }}</p>
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
</div>
