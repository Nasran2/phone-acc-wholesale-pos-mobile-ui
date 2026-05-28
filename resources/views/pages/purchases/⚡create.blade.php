<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\TextItSmsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

new #[Title('Record Wholesale Purchase')] class extends Component
{
    public string $invoice_no = '';
    public string $date = '';
    public ?int $supplier_id = null;

    // Cart and item search state
    public string $productSearch = '';
    public array $cart = [];

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
    public string $notes = '';

    public function mount(): void
    {
        $this->date = date('Y-m-d');
        // Generate automatic purchase ref
        $this->invoice_no = 'PUR-' . date('ymd') . '-' . rand(100, 999);

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

    public function updatedPaymentMethod(): void
    {
        $this->syncAutoPaidAmount();
    }

    public function updatedDiscount(): void
    {
        $this->syncAutoPaidAmount();
    }

    private function syncAutoPaidAmount(): void
    {
        if (in_array($this->payment_method, ['cash', 'bank_transfer'], true)) {
            $this->paid_amount = max(0.00, (float) $this->cartTotal);
        }
    }

    public function savePurchase(): void
    {
        $rules = [
            'invoice_no' => 'required|string|unique:purchases,invoice_no',
            'date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'cart' => 'required|array|min:1',
            'cart.*.product_id' => 'required|exists:products,id',
            'cart.*.quantity' => 'required|integer|min:1',
            'cart.*.cost_price' => 'required|numeric|min:0',
            'cart.*.selling_price' => 'required|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,cheque',
        ];

        if ($this->payment_method === 'cheque') {
            $rules['paid_amount'] = 'required|numeric|min:0.01';
            $rules['cheque_type'] = 'required|in:own,party';
            $rules['cheque_date'] = 'required_if:cheque_type,own|nullable|date';
            $rules['cheque_no'] = 'required_if:cheque_type,own|nullable|string|max:100';
            $rules['cheque_bank'] = 'nullable|string|max:100';
            $rules['party_cheque_payment_id'] = 'required_if:cheque_type,party|nullable|exists:payments,id';
        }

        $this->validate($rules);

        $subtotal = $this->cartSubtotal;
        $grandTotal = $subtotal - (float) $this->discount;
        $isChequePayment = $this->payment_method === 'cheque';
        $partyCheque = $this->selectedPartyCheque;

        if ($isChequePayment && $this->cheque_type === 'party' && (! $partyCheque || (float) $this->paid_amount > (float) $partyCheque->amount)) {
            $this->addError('paid_amount', __('Party cheque amount cannot exceed the selected customer cheque amount.'));

            return;
        }

        $paymentAmount = min((float) $this->paid_amount, $grandTotal);
        $capturedPaidAmount = $isChequePayment ? 0.00 : $paymentAmount;
        $heldChequeAmount = $isChequePayment ? $paymentAmount : 0.00;
        $dueAmount = max(0.00, $grandTotal - $capturedPaidAmount - $heldChequeAmount);

        $paymentStatus = $isChequePayment ? ($dueAmount > 0 ? 'partial' : 'cheque_pending') : 'due';
        if (! $isChequePayment && $capturedPaidAmount >= $grandTotal) {
            $paymentStatus = 'paid';
        } elseif (! $isChequePayment && $capturedPaidAmount > 0) {
            $paymentStatus = 'partial';
        }

        DB::transaction(function () use ($subtotal, $grandTotal, $capturedPaidAmount, $dueAmount, $paymentStatus, $paymentAmount, $isChequePayment, $partyCheque): void {
            // 1. Create Purchase Invoice
            $purchase = Purchase::query()->create([
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
            ]);

            // 2. Process Cart Items
            foreach ($this->cart as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                    'subtotal' => $item['subtotal'],
                ]);

                // Adjust inventory stock levels & pricing defaults
                $product = Product::query()->findOrFail($item['product_id']);
                $product->increment('stock_quantity', $item['quantity']);
                $product->update([
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                ]);
            }

            // 3. Log outward payment or cheque hold if paid
            if ($paymentAmount > 0) {
                $purchase->payments()->create([
                    'amount' => $paymentAmount,
                    'payment_method' => $this->payment_method,
                    'date' => $this->date,
                    'reference' => $isChequePayment ? ($this->cheque_type === 'party' ? $partyCheque?->cheque_no : $this->cheque_no) : $this->payment_reference,
                    'cheque_bank' => $isChequePayment ? ($this->cheque_type === 'party' ? $partyCheque?->cheque_bank : $this->cheque_bank) : null,
                    'cheque_no' => $isChequePayment ? ($this->cheque_type === 'party' ? $partyCheque?->cheque_no : $this->cheque_no) : null,
                    'cheque_date' => $isChequePayment ? ($this->cheque_type === 'party' ? $partyCheque?->cheque_date : $this->cheque_date) : null,
                    'cheque_status' => $isChequePayment ? 'pending' : null,
                    'cheque_type' => $isChequePayment ? $this->cheque_type : null,
                    'source_payment_id' => $isChequePayment && $this->cheque_type === 'party' ? $partyCheque?->id : null,
                    'party_customer_id' => $isChequePayment && $this->cheque_type === 'party' ? $partyCheque?->paymentable?->customer_id : null,
                    'notes' => $isChequePayment ? 'Supplier cheque payment on hold until cleared.' : 'Restock purchase invoice payments.',
                ]);
            }

            // 4. Update supplier outstanding accounts payable due
            if ($dueAmount > 0) {
                $supplier = Supplier::query()->findOrFail($this->supplier_id);
                $supplier->increment('due_balance', $dueAmount);
            }
        });

        ActivityLogger::log('purchase_create', "Registered restock invoice {$this->invoice_no}. Total: Rs {$grandTotal}, Supplier Dues: Rs {$dueAmount}.");
        Flux::toast(variant: 'success', text: __('Purchase restock successfully recorded.'));

        $this->redirectRoute('purchases.index', navigate: true);
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->orderBy('name')->get();
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

    #[Computed]
    public function partyCheques()
    {
        if (empty($this->party_cheque_search)) {
            return [];
        }

        return \App\Models\Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->whereDoesntHave('issuedPayments', fn ($query) => $query->where('cheque_status', 'pending'))
            ->where(function ($query): void {
                $query->where('cheque_no', 'like', '%' . $this->party_cheque_search . '%')
                    ->orWhere('reference', 'like', '%' . $this->party_cheque_search . '%');
            })
            ->with('paymentable.customer')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function selectedPartyCheque()
    {
        if (! $this->party_cheque_payment_id) {
            return null;
        }

        return \App\Models\Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->with('paymentable.customer')
            ->find($this->party_cheque_payment_id);
    }

    public function selectPartyCheque(int $paymentId): void
    {
        $payment = \App\Models\Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->with('paymentable.customer')
            ->findOrFail($paymentId);

        $this->party_cheque_payment_id = $payment->id;
        $this->party_cheque_search = $payment->cheque_no ?: (string) $payment->reference;
        $this->paid_amount = min((float) $payment->amount, $this->cartTotal);
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
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Wholesale Restock Invoice') }}</h1>
        <p class="text-sm text-zinc-500">{{ __('Record incoming warehouse accessories shipments, register supplier invoices, adjust purchase values and automatically update warehouse stock count.') }}</p>
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
                    <flux:select wire:model.live="supplier_id" :label="__('Wholesale Vendor')" placeholder="Choose Supplier">
                        @foreach ($this->suppliers as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }} ({{ $sup->company_name ?: 'Distributor' }})</option>
                        @endforeach
                    </flux:select>
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
                <div class="flex flex-col gap-1 border-b border-zinc-100 pb-3">
                    <h3 class="font-display text-sm font-semibold text-zinc-900">{{ __('Invoice Restock Catalog Items') }}</h3>
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
                            <div class="grid gap-3 grid-cols-3">
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

                <!-- Fast Payment Inputs -->
                <div class="flex flex-col gap-4">
                    <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Capture Outward Payment') }}</h4>

                    <flux:input wire:model.live.number="paid_amount" :label="$payment_method === 'cheque' ? __('Cheque Amount (Rs)') : __('Cash / Bank Amount Paid (Rs)')" type="number" step="0.01" />

                    @if ($paid_amount > 0)
                        <flux:select wire:model.live="payment_method" :label="__('Paid Account')">
                            <option value="cash">Cash Account</option>
                            <option value="bank_transfer">Direct Bank Transfer</option>
                            <option value="cheque">Cheque Hold</option>
                        </flux:select>

                        @if ($payment_method === 'cheque')
                            <flux:select wire:model.live="cheque_type" :label="__('Cheque Type')">
                                <option value="own">{{ __('Own Cheque') }}</option>
                                <option value="party">{{ __('Party Cheque') }}</option>
                            </flux:select>

                            @if ($cheque_type === 'own')
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:input wire:model="cheque_no" :label="__('Own Cheque No')" placeholder="Cheque number" required />
                                    <flux:input wire:model="cheque_bank" :label="__('Bank')" placeholder="Bank name" />
                                </div>
                                <flux:input wire:model="cheque_date" :label="__('Cheque Date')" type="date" required />
                                <p class="rounded-2xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                                    {{ __('Own cheques are shown on the dashboard from 3 days before the cheque date and become cash out when marked passed.') }}
                                </p>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.away="open = false">
                                    <flux:input wire:model.live.debounce.150ms="party_cheque_search" :label="__('Customer Cheque No')" placeholder="Search pending customer cheque..." @focus="open = true" required />

                                    @if (count($this->partyCheques) > 0)
                                        <div x-cloak x-show="open" class="absolute z-40 mt-2 max-h-60 w-full overflow-y-auto rounded-2xl border border-zinc-100 bg-white p-2 shadow-xl">
                                            @foreach ($this->partyCheques as $partyCheque)
                                                @php($partySale = $partyCheque->paymentable)
                                                <button type="button" wire:click="selectPartyCheque({{ $partyCheque->id }})" @click="open = false" class="w-full rounded-xl p-3 text-left transition hover:bg-zinc-50">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <span class="text-sm font-bold text-zinc-900">{{ $partyCheque->cheque_no ?: $partyCheque->reference }}</span>
                                                        <span class="text-xs font-black text-violet-600">Rs {{ number_format($partyCheque->amount, 2) }}</span>
                                                    </div>
                                                    <p class="mt-0.5 text-xs text-zinc-500">
                                                        {{ $partySale?->customer?->name ?? __('Unknown Customer') }} · {{ $partySale?->invoice_no }} · {{ $partyCheque->cheque_date?->format('Y-m-d') }}
                                                    </p>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                @if ($this->selectedPartyCheque)
                                    @php($selectedPartySale = $this->selectedPartyCheque->paymentable)
                                    <div class="rounded-2xl border border-violet-100 bg-violet-50 p-3 text-xs text-violet-900">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-black">{{ $this->selectedPartyCheque->cheque_no ?: $this->selectedPartyCheque->reference }}</span>
                                            <span class="font-black">Rs {{ number_format($this->selectedPartyCheque->amount, 2) }}</span>
                                        </div>
                                        <p class="mt-1 font-semibold">
                                            {{ $selectedPartySale?->customer?->name ?? __('Unknown Customer') }} · {{ __('Due') }} {{ $this->selectedPartyCheque->cheque_date?->format('Y-m-d') }}
                                        </p>
                                    </div>
                                @endif

                                <p class="rounded-2xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                                    {{ __('Party cheques are shown separately on the dashboard from 2 days before the cheque date with supplier bill, supplier, customer, passed, and return actions.') }}
                                </p>
                            @endif
                        @else
                            <flux:input wire:model="payment_reference" :label="__('Transaction Receipt Reference')" placeholder="e.g. Bank slip #" />
                        @endif
                    @endif

                    <div class="flex justify-between text-sm rounded-2xl bg-zinc-50 p-4 border border-zinc-100">
                        <span class="text-zinc-500 font-medium">Outstanding Vendor Due</span>
                        <span class="font-bold text-rose-600">Rs {{ number_format($payment_method === 'cheque' ? max(0.00, $this->cartTotal - min((float) $paid_amount, $this->cartTotal)) : max(0.00, $this->cartTotal - (float) $this->paid_amount), 2) }}</span>
                    </div>

                    <flux:textarea wire:model="notes" :label="__('Internal restock details')" rows="2" />

                    <flux:button type="button" wire:click="savePurchase" variant="primary" class="w-full mt-2">
                        <flux:icon.check class="size-4 mr-1" />
                        {{ __('Record Restock Invoice') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </div>
</div>
