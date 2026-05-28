<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\SmsNotificationService;
use App\Services\TextItSmsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sales Receipts')] class extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';
    public ?int $selectedCustomer = null;
    public string $paymentStatus = 'ALL';
    public string $dateRange = 'this_month';
    public ?string $startDate = null;
    public ?string $endDate = null;

    // Pay due state
    public bool $payDueModalOpen = false;
    public $payDueAmount = 0.00;
    public string $payDueMethod = 'cash';
    public string $payDueReference = '';
    public string $payDueDate = '';
    public string $payDueChequeBank = '';
    public string $payDueChequeNo = '';
    public string $payDueChequeDate = '';

    // Detail view state
    public ?int $viewingSaleId = null;

    // Returns processing state
    public bool $returnModalOpen = false;
    public array $returnItems = []; // product_id => ['quantity' => x, 'refund_price' => x, 'max' => x]
    public string $returnType = 'cash_refund'; // cash_refund, adjust_due, exchange
    public string $returnNotes = '';

    protected $queryString = ['search', 'selectedCustomer', 'paymentStatus', 'dateRange', 'startDate', 'endDate'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function viewInvoice(int $id): void
    {
        $this->viewingSaleId = $id;
    }

    public function closeInvoice(): void
    {
        $this->viewingSaleId = null;
    }

    public function initiateReturn(): void
    {
        if (! auth()->user()->hasPermission('process_return')) {
            Flux::toast(variant: 'danger', text: __('You do not have authorization to process returns.'));
            return;
        }

        $sale = Sale::query()->with('items.product')->findOrFail($this->viewingSaleId);
        
        $this->returnItems = [];
        foreach ($sale->items as $item) {
            // Check if already returned in other receipts, max return limit
            $alreadyReturned = \App\Models\SaleReturnItem::query()
                ->whereHas('returnLog', fn($q) => $q->where('sale_id', $sale->id))
                ->where('product_id', $item->product_id)
                ->sum('quantity');

            $maxReturnable = max(0, $item->quantity - $alreadyReturned);

            if ($maxReturnable > 0) {
                $this->returnItems[$item->product_id] = [
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'quantity' => 0,
                    'refund_price' => (float) $item->selling_price,
                    'max' => $maxReturnable,
                    'subtotal' => 0.00,
                ];
            }
        }

        if (count($this->returnItems) === 0) {
            Flux::toast(variant: 'danger', text: __('All items in this invoice have already been returned.'));
            return;
        }

        // Set default return type if invoice had dues
        if ($sale->due_amount > 0) {
            $this->returnType = 'adjust_due';
        } else {
            $this->returnType = 'cash_refund';
        }

        $this->returnModalOpen = true;
    }

    public function updateReturnQty(int $productId, int $qty): void
    {
        if (isset($this->returnItems[$productId])) {
            $max = $this->returnItems[$productId]['max'];
            $qty = min($max, max(0, $qty));

            $this->returnItems[$productId]['quantity'] = $qty;
            $this->returnItems[$productId]['subtotal'] = $qty * $this->returnItems[$productId]['refund_price'];
        }
    }

    public function submitReturn(TextItSmsService $smsService): void
    {
        $this->validate([
            'returnItems' => 'required|array',
            'returnType' => 'required|string',
            'returnNotes' => 'nullable|string',
        ]);

        $sale = Sale::query()->with(['customer', 'items'])->findOrFail($this->viewingSaleId);
        $customer = $sale->customer;

        // Calculate refund grand total
        $refundTotal = 0.00;
        $itemsToReturn = [];
        foreach ($this->returnItems as $prodId => $item) {
            if ($item['quantity'] > 0) {
                $refundTotal += $item['subtotal'];
                $itemsToReturn[] = array_merge($item, ['product_id' => $prodId]);
            }
        }

        if ($refundTotal <= 0) {
            Flux::toast(variant: 'danger', text: __('Select at least one product quantity to return.'));
            return;
        }

        // Handle calculations & logic bounds based on type
        $adjustedAmount = 0.00;
        $refundAmount = 0.00;

        if ($this->returnType === 'adjust_due') {
            if ($sale->due_amount <= 0) {
                Flux::toast(variant: 'danger', text: __('No outstanding dues exist on this invoice to adjust.'));
                return;
            }

            // Adjust invoice due
            $adjustedAmount = min($refundTotal, $sale->due_amount);
            $refundAmount = max(0.00, $refundTotal - $adjustedAmount);

            // Decrement invoice due & customer account due
            $sale->decrement('due_amount', $adjustedAmount);
            $customer->decrement('due_balance', $adjustedAmount);
            
            // Re-eval payment status
            $newDue = $sale->due_amount;
            if ($newDue <= 0) {
                $sale->update(['payment_status' => 'paid']);
            }
        } else {
            $refundAmount = $refundTotal;
        }

        // Generate return ref
        $returnNo = 'RET-' . date('ymd') . '-' . rand(100, 999);

        // 1. Create Return transaction
        $retLog = SaleReturn::query()->create([
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'invoice_no' => $returnNo,
            'date' => date('Y-m-d'),
            'return_type' => $this->returnType,
            'refund_amount' => $refundAmount,
            'adjusted_amount' => $adjustedAmount,
            'notes' => $this->returnNotes ?: 'POS Customer Return request.',
        ]);

        // 2. Process items returned
        foreach ($itemsToReturn as $item) {
            $retLog->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'refund_price' => $item['refund_price'],
                'subtotal' => $item['subtotal'],
            ]);

            // Restock returned items back to store inventory
            $product = Product::query()->findOrFail($item['product_id']);
            $product->increment('stock_quantity', $item['quantity']);
        }

        // 3. Log outward payment if cash refunded
        if ($refundAmount > 0 && $this->returnType === 'cash_refund') {
            $retLog->payments()->create([
                'amount' => $refundAmount,
                'payment_method' => 'cash',
                'date' => date('Y-m-d'),
                'notes' => 'Cash refund for sale return ' . $returnNo,
            ]);
        }

        ActivityLogger::log('pos_return', "Processed Customer Return {$returnNo} for invoice {$sale->invoice_no}. Total refund: Rs {$refundTotal}.");
        Flux::toast(variant: 'success', text: __('Return transaction logged successfully.'));

        // 4. SMS Alert Dispatch
        if (Setting::get('sms_enabled') === '1' && ! empty($customer->phone) && $customer->phone !== '0000000000') {
            $template = Setting::get('sms_template_return', 'Processed return for invoice {invoice_no}. Refund: Rs {total}.');
            $msg = $smsService->parseTemplate($template, [
                'customer_name' => $customer->name,
                'invoice_no' => $sale->invoice_no,
                'total' => number_format($refundTotal, 2),
            ]);
            $smsService->sendSms($customer->phone, $msg, 'RET-' . $retLog->id);
        }

        $this->returnModalOpen = false;
        $this->closeInvoice();
    }

    public function deleteSale(): void
    {
        $sale = Sale::query()->with(['items', 'returns.items'])->findOrFail($this->viewingSaleId);
        
        $itemQuantities = [];
        foreach ($sale->items as $item) {
            $itemQuantities[$item->product_id] = $item->quantity;
        }
        
        foreach ($sale->returns as $ret) {
            foreach ($ret->items as $retItem) {
                if (isset($itemQuantities[$retItem->product_id])) {
                    $itemQuantities[$retItem->product_id] -= $retItem->quantity;
                }
            }
        }
        
        foreach ($itemQuantities as $productId => $qty) {
            if ($qty > 0) {
                $product = Product::query()->find($productId);
                if ($product) {
                    $product->increment('stock_quantity', $qty);
                }
            }
        }
        
        $sale->payments()->delete();
        foreach ($sale->returns as $ret) {
            $ret->items()->delete();
            $ret->payments()->delete();
            $ret->delete();
        }
        $sale->items()->delete();
        $sale->delete();
        
        ActivityLogger::log('sale_delete', "Deleted Sale {$sale->invoice_no} and restored inventory stock.");
        Flux::toast(variant: 'success', text: __('Sale deleted and stock rearranged successfully.'));
        $this->closeInvoice();
    }

    public function openPayDueModal(): void
    {
        $sale = Sale::query()->findOrFail($this->viewingSaleId);
        $this->payDueAmount = $sale->due_amount;
        $this->payDueMethod = 'cash';
        $this->payDueReference = '';
        $this->payDueDate = date('Y-m-d');
        $this->payDueChequeBank = '';
        $this->payDueChequeNo = '';
        $this->payDueChequeDate = date('Y-m-d');
        $this->payDueModalOpen = true;
    }

    public function submitPayDue(SmsNotificationService $smsNotificationService): void
    {
        $this->validate([
            'payDueAmount' => 'required|numeric|min:0.01',
            'payDueMethod' => 'required|in:cash,card,bank_transfer,cheque',
            'payDueDate' => 'required|date',
            'payDueChequeBank' => 'nullable|string|max:100',
            'payDueChequeNo' => 'nullable|string|max:100',
            'payDueChequeDate' => 'required_if:payDueMethod,cheque|nullable|date',
        ]);

        $sale = Sale::query()->with('customer')->findOrFail($this->viewingSaleId);
        
        if ($this->payDueAmount > $sale->due_amount) {
            $this->addError('payDueAmount', 'Amount exceeds due balance.');
            return;
        }

        $isCheque = $this->payDueMethod === 'cheque';

        $payment = $sale->payments()->create([
            'amount' => $this->payDueAmount,
            'payment_method' => $this->payDueMethod,
            'date' => $this->payDueDate,
            'reference' => $isCheque ? ($this->payDueChequeNo ?: $this->payDueReference) : $this->payDueReference,
            'cheque_bank' => $isCheque ? ($this->payDueChequeBank ?: null) : null,
            'cheque_no' => $isCheque ? ($this->payDueChequeNo ?: null) : null,
            'cheque_date' => $isCheque ? $this->payDueChequeDate : null,
            'cheque_status' => $isCheque ? 'pending' : null,
            'notes' => $isCheque ? 'Cheque payment on hold until cleared.' : 'Due payment received',
        ]);

        $sale->decrement('due_amount', $this->payDueAmount);

        if (! $isCheque) {
            $sale->increment('paid_amount', $this->payDueAmount);
        }

        if ($isCheque) {
            $sale->update(['payment_status' => 'cheque_pending']);
        } elseif ($sale->due_amount <= 0) {
            $sale->update(['payment_status' => 'paid']);
        } else {
            $sale->update(['payment_status' => 'partial']);
        }

        if ($sale->customer_id) {
            Customer::query()->where('id', $sale->customer_id)->decrement('due_balance', $this->payDueAmount);
        }

        ActivityLogger::log('sale_payment', "Received due payment of Rs {$this->payDueAmount} for Sale {$sale->invoice_no}.");
        $smsNotificationService->notifyPaymentReceived($sale->refresh(), $payment);
        Flux::toast(variant: 'success', text: __('Due payment recorded successfully.'));
        $this->payDueModalOpen = false;
        $this->dispatch('payment-added');
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()->orderBy('name')->get();
    }

    #[Computed]
    public function filteredSalesQuery()
    {
        $query = Sale::query()->with(['customer', 'items.product']);

        if ($this->search) {
            $query->where('invoice_no', 'like', '%' . $this->search . '%');
        }

        if ($this->selectedCustomer) {
            $query->where('customer_id', $this->selectedCustomer);
        }

        if ($this->paymentStatus !== 'ALL') {
            $query->where('payment_status', $this->paymentStatus);
        }

        if ($this->dateRange === 'today') {
            $query->whereDate('date', date('Y-m-d'));
        } elseif ($this->dateRange === 'yesterday') {
            $query->whereDate('date', date('Y-m-d', strtotime('-1 day')));
        } elseif ($this->dateRange === 'this_week') {
            $query->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($this->dateRange === 'last_week') {
            $query->whereBetween('date', [now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString()]);
        } elseif ($this->dateRange === 'this_month') {
            $query->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        } elseif ($this->dateRange === 'last_month') {
            $query->whereBetween('date', [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()]);
        } elseif ($this->dateRange === 'this_year') {
            $query->whereBetween('date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()]);
        } elseif ($this->dateRange === 'last_year') {
            $query->whereBetween('date', [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()]);
        } elseif ($this->dateRange === 'custom') {
            if ($this->startDate) {
                $query->whereDate('date', '>=', $this->startDate);
            }
            if ($this->endDate) {
                $query->whereDate('date', '<=', $this->endDate);
            }
        }

        return $query;
    }

    #[Computed]
    public function sales()
    {
        return $this->filteredSalesQuery()
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    #[Computed]
    public function selectedSale()
    {
        if (! $this->viewingSaleId) return null;

        return Sale::query()
            ->with(['customer', 'items.product', 'payments', 'returns.items.product'])
            ->findOrFail($this->viewingSaleId);
    }
}; ?>

<div class="flex flex-col gap-6" @payment-added.window="resetSharePdf()" x-data="{ 
    drawerOpen: @entangle('viewingSaleId'), 
    retModalOpen: @entangle('returnModalOpen'), 
    payDueOpen: @entangle('payDueModalOpen'),
    shareCopied: false,
    sharePreparing: false,
    sharePdfError: false,
    sharePdfFile: null,
    sharePdfUrl: null,
    init() {
        this.$watch('drawerOpen', (val) => {
            if (!val) this.resetSharePdf();
        });
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
        if (globalChecker()) return;
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
            script.onload = () => { clearTimeout(timeout); resolve(); };
            script.onerror = () => { clearTimeout(timeout); reject(new Error(`Failed to load ${src}`)); };
            document.head.appendChild(script);
        });
    },
    async prepareBillPdf() {
        if (this.sharePreparing || this.sharePdfFile) return;

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
            await this.loadPdfScript('/vendor/pos-share/html2canvas-pro.min.js', () => typeof window.html2canvas !== 'undefined');
            await this.loadPdfScript('/vendor/pos-share/jspdf.umd.min.js', () => typeof window.jsPDF !== 'undefined' || !!window.jspdf?.jsPDF);

            if (window.jspdf?.jsPDF && typeof window.jsPDF === 'undefined') {
                window.jsPDF = window.jspdf.jsPDF;
            }

            if (typeof window.html2canvas === 'undefined' || typeof window.jsPDF === 'undefined') {
                throw new Error('PDF generator libraries are unavailable.');
            }

            const wrapper = document.createElement('div');
            wrapper.style.position = 'fixed';
            wrapper.style.top = '-9999px';
            wrapper.style.left = '-9999px';
            
            if (isA4) {
                wrapper.style.width = '794px';
                wrapper.style.height = '1123px';
            } else {
                wrapper.style.width = '300px'; 
            }
            
            wrapper.style.background = 'white';
            wrapper.style.zIndex = '-1';
            
            const clonedEl = originalEl.cloneNode(true);
            clonedEl.classList.remove('hidden', 'print:block');
            clonedEl.style.display = 'block';
            
            if (isA4) {
                clonedEl.style.width = '794px';
                clonedEl.style.height = '1123px';
                clonedEl.style.margin = '0';
                clonedEl.style.padding = '0';
            }
            
            wrapper.appendChild(clonedEl);
            document.body.appendChild(wrapper);

            const canvas = await html2canvas(clonedEl, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
                logging: false,
                windowWidth: isA4 ? 794 : 300,
                width: isA4 ? 794 : 300,
                height: isA4 ? 1123 : clonedEl.offsetHeight
            });
            
            document.body.removeChild(wrapper);

            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const pdf = new window.jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: isA4 ? 'a4' : [80, (canvas.height * 80) / canvas.width]
            });
            
            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
            
            pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
            
            const pdfBlob = pdf.output('blob');
            this.sharePdfFile = new File([pdfBlob], `${invoiceNo}.pdf`, { type: 'application/pdf' });
            this.sharePdfUrl = URL.createObjectURL(pdfBlob);
        } catch (e) {
            console.error('PDF Generation Error:', e);
            this.sharePdfError = true;
        } finally {
            this.sharePreparing = false;
        }
    },
    async sharePdfAction() {
        if (!this.sharePdfFile) return;

        if (navigator.canShare && navigator.canShare({ files: [this.sharePdfFile] })) {
            try {
                await navigator.share({
                    title: 'Invoice Receipt',
                    text: 'Please find your invoice receipt attached.',
                    files: [this.sharePdfFile]
                });
                return;
            } catch (err) {
                if (err.name !== 'AbortError') console.error('Share failed:', err);
            }
        }
        
        const a = document.createElement('a');
        a.href = this.sharePdfUrl;
        a.download = this.sharePdfFile.name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}">
    <div class="flex flex-col gap-2">
        <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Sales Invoices & Returns') }}</h1>
        <p class="text-sm text-zinc-500">{{ __('Auditing digital retail cash receipts, outstanding due statements, and customer product return exchanges.') }}</p>
    </div>

    <!-- Multi-criteria filter bar -->
    <div class="app-card p-4 grid gap-4 sm:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by Invoice No..." />
        
        <flux:select wire:model.live="selectedCustomer" placeholder="All Customers">
            @foreach ($this->customers as $cust)
                <option value="{{ $cust->id }}">{{ $cust->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="paymentStatus">
            <option value="ALL">All Payment Statuses</option>
            <option value="paid">Fully Paid Invoices</option>
            <option value="partial">Partially Paid</option>
            <option value="cheque_pending">Cheque Holds</option>
            <option value="due">Pending Outstanding Dues</option>
        </flux:select>

        <div class="flex flex-col gap-2">
            <flux:select wire:model.live="dateRange">
                <option value="ALL">All Periods</option>
                <option value="today">Today</option>
                <option value="yesterday">Yesterday</option>
                <option value="this_week">This Week</option>
                <option value="last_week">Last Week</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_year">This Year</option>
                <option value="last_year">Last Year</option>
                <option value="custom">Custom Date Range</option>
            </flux:select>
            
            @if ($dateRange === 'custom')
                <div class="flex items-center gap-2 mt-2">
                    <flux:input type="date" wire:model.live="startDate" class="w-full" />
                    <span class="text-xs text-zinc-400">to</span>
                    <flux:input type="date" wire:model.live="endDate" class="w-full" />
                </div>
            @endif
        </div>
    </div>

    <!-- Listings -->
    <div class="grid gap-3">
        @forelse ($this->sales as $s)
            <div
                class="app-card p-4 flex flex-col gap-4 justify-between sm:flex-row sm:items-center hover:bg-zinc-50/50 transition cursor-pointer"
                wire:click="viewInvoice({{ $s->id }})"
                wire:key="sale-item-card-{{ $s->id }}"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-600">
                        <flux:icon.arrow-up-right class="size-5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-zinc-900">{{ $s->invoice_no }}</span>
                            @if ($s->payment_status === 'paid')
                                <flux:badge size="sm" color="emerald">Paid</flux:badge>
                            @elseif ($s->payment_status === 'partial')
                                <flux:badge size="sm" color="orange">Partial</flux:badge>
                            @elseif ($s->payment_status === 'cheque_pending')
                                <flux:badge size="sm" color="amber">Cheque Hold</flux:badge>
                            @else
                                <flux:badge size="sm" color="rose">Due</flux:badge>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                            <span>{{ $s->date->format('Y-m-d') }}</span>
                            <span class="inline-block h-1 w-1 rounded-full bg-zinc-300"></span>
                            <span class="font-semibold text-zinc-700">{{ $s->customer?->name }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-zinc-100 pt-3 sm:border-t-0 sm:pt-0 sm:gap-6">
                    <div class="flex flex-col sm:items-end">
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">Grand Total</span>
                        <span class="text-sm font-bold text-zinc-950">Rs {{ number_format($s->grand_total, 2) }}</span>
                    </div>

                    <div class="flex flex-col sm:items-end">
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">Remaining Due</span>
                        <span class="text-sm font-semibold {{ $s->due_amount > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            Rs {{ number_format($s->due_amount, 2) }}
                        </span>
                    </div>

                    <flux:button size="sm" variant="ghost" class="hidden sm:flex" wire:click.stop="viewInvoice({{ $s->id }})">
                        <flux:icon.eye class="size-4 mr-1" />
                        View
                    </flux:button>
                </div>
            </div>
        @empty
            <div class="py-12 text-center text-xs text-zinc-400 bg-white rounded-3xl border border-zinc-100 shadow-sm">
                {{ __('No retail invoice receipts found.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-2">
        {{ $this->sales->links() }}
    </div>

    <!-- Centered detailed receipt Viewer -->
    <div
        x-cloak
        x-show="drawerOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.self="drawerOpen = null; $wire.closeInvoice()"
    >
        <div
            class="w-full max-w-xl bg-white rounded-3xl shadow-2xl flex flex-col overflow-hidden max-h-[95%]"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            @if ($this->selectedSale)
                <!-- Drawer Header -->
                <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                    <div>
                        <h3 class="font-display font-bold text-zinc-950" x-ref="shareBillTitle">
                            {{ $this->selectedSale->invoice_no }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-1">{{ __('Retail Receipt Audit View') }}</p>
                    </div>
                    <flux:button variant="ghost" size="sm" wire:click="closeInvoice">
                        <flux:icon.x-mark class="size-4" />
                    </flux:button>
                </div>

                <!-- Invoice Content Scroll View -->
                <div class="flex-1 overflow-y-auto p-5 scrollbar-none flex flex-col gap-5">
                    <!-- Customer quick contact -->
                    <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4 flex justify-between items-center text-xs">
                        <div>
                            <span class="text-[10px] text-zinc-400 font-semibold uppercase tracking-wider block">Customer Billing</span>
                            <span class="font-bold text-zinc-900 text-sm mt-0.5 block">{{ $this->selectedSale->customer?->name }}</span>
                            @if ($this->selectedSale->customer?->phone)
                                <span class="text-zinc-500 mt-0.5 block">{{ $this->selectedSale->customer?->phone }}</span>
                            @endif
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] text-zinc-400 font-semibold uppercase tracking-wider block">Receipt Date</span>
                            <span class="font-semibold text-zinc-800 text-sm mt-0.5 block">{{ $this->selectedSale->date->format('Y-m-d') }}</span>
                        </div>
                    </div>

                    <!-- Items listing -->
                    <div class="flex flex-col gap-2">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1">{{ __('Products Sold') }}</h4>
                        
                        @foreach ($this->selectedSale->items as $item)
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-2.5 text-xs">
                                <div>
                                    <h5 class="font-bold text-zinc-900">{{ $item->product?->name }}</h5>
                                    <span class="text-[10px] text-zinc-400 uppercase font-mono mt-0.5 block">
                                        Qty: <span class="font-semibold text-zinc-800">{{ $item->quantity }}</span> | Unit: Rs {{ number_format($item->selling_price, 2) }}
                                    </span>
                                </div>
                                <span class="font-bold text-zinc-950">Rs {{ number_format($item->subtotal, 2) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Financial Summary -->
                    <div class="border-t border-zinc-100 pt-4 flex flex-col gap-2 text-xs">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Subtotal</span>
                            <span class="font-semibold text-zinc-900">Rs {{ number_format($this->selectedSale->subtotal_amount, 2) }}</span>
                        </div>
                        @if ($this->selectedSale->discount_amount > 0)
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Invoice Discount</span>
                                <span class="font-semibold text-rose-600">- Rs {{ number_format($this->selectedSale->discount_amount, 2) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Order Tax</span>
                            <span class="font-semibold text-zinc-900">+ Rs {{ number_format($this->selectedSale->tax_amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-zinc-100 pt-2 text-sm">
                            <span class="font-bold text-zinc-950">Grand Total</span>
                            <span class="font-bold text-orange-600">Rs {{ number_format($this->selectedSale->grand_total, 2) }}</span>
                        </div>
                    </div>

                    <!-- Polymorphic payments list -->
                    <div class="border-t border-zinc-100 pt-4 flex flex-col gap-3">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Receipt Payments Logs') }}</h4>
                        
                        @foreach ($this->selectedSale->payments as $pm)
                            <div class="flex items-center justify-between rounded-xl bg-zinc-50 border border-zinc-100 p-3 text-xs">
                                <div>
                                    <span class="font-bold text-zinc-800 capitalize">{{ $pm->payment_method }} Mode</span>
                                    <span class="text-[10px] text-zinc-400 mt-0.5 block">{{ $pm->date->format('Y-m-d') }}</span>
                                    @if ($pm->payment_method === 'cheque')
                                        <span class="mt-1 block text-[10px] font-bold uppercase tracking-wide text-amber-700">
                                            {{ __('Cheque') }} {{ $pm->cheque_no ?: 'N/A' }} · {{ $pm->cheque_date?->format('Y-m-d') }} · {{ strtoupper($pm->cheque_status ?? 'pending') }}
                                        </span>
                                    @endif
                                </div>
                                <span class="font-bold text-emerald-600">Rs {{ number_format($pm->amount, 2) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Returns list histories if any exists -->
                    @if (count($this->selectedSale->returns) > 0)
                        <div class="border-t border-zinc-100 pt-4 flex flex-col gap-3">
                            <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider text-rose-600">{{ __('Invoice Returns History') }}</h4>
                            
                            @foreach ($this->selectedSale->returns as $ret)
                                <div class="flex flex-col gap-2 rounded-2xl bg-rose-50/20 border border-rose-100 p-3 text-xs">
                                    <div class="flex justify-between items-center font-bold text-zinc-900">
                                        <span>{{ $ret->invoice_no }}</span>
                                        <span class="text-rose-600">Rs {{ number_format($ret->refund_amount + $ret->adjusted_amount, 2) }}</span>
                                    </div>
                                    <span class="text-[10px] text-zinc-400">{{ $ret->date->format('Y-m-d') }} | Type: {{ str_replace('_', ' ', strtoupper($ret->return_type)) }}</span>
                                    
                                    <!-- Items returned list in this log -->
                                    <div class="flex flex-col gap-1 border-t border-rose-100/50 pt-2 mt-1">
                                        @foreach ($ret->items as $ri)
                                            <div class="flex justify-between text-[10px] text-zinc-600">
                                                <span>{{ $ri->product?->name }} (x{{ $ri->quantity }})</span>
                                                <span>Rs {{ number_format($ri->subtotal, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <!-- Quick Print / Returns Actions drawer footer -->
                    <div class="border-t border-zinc-100 pt-4 flex flex-col gap-2">
                        @if ($this->selectedSale->due_amount > 0)
                            <flux:button type="button" wire:click="openPayDueModal" variant="primary" class="w-full">
                                <flux:icon.banknotes class="size-4 mr-1" />
                                Pay Due Balance
                            </flux:button>
                        @endif
                        
                        <div class="grid grid-cols-2 gap-2 mt-1">
                            <flux:button type="button" onclick="window.print()" variant="outline" class="w-full text-zinc-600">
                                <flux:icon.printer class="size-4 mr-2" />
                                Print
                            </flux:button>

                            <flux:button type="button" @click="prepareBillPdf()" variant="outline" class="w-full text-zinc-600">
                                <template x-if="sharePreparing">
                                    <flux:icon.arrow-path class="size-4 mr-2 animate-spin" />
                                </template>
                                <template x-if="!sharePreparing">
                                    <flux:icon.share class="size-4 mr-2" />
                                </template>
                                <span x-text="sharePreparing ? 'Preparing...' : 'Share PDF'"></span>
                            </flux:button>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <flux:button as="a" href="/pos/{{ $this->selectedSale->id }}" variant="subtle" class="w-full">
                                <flux:icon.pencil-square class="size-4 mr-2" />
                                Edit
                            </flux:button>
                            
                            @if (auth()->user()->hasPermission('process_return'))
                                <flux:button type="button" wire:click="initiateReturn" variant="subtle" class="w-full text-orange-600">
                                    <flux:icon.arrow-uturn-left class="size-4 mr-2" />
                                    Return
                                </flux:button>
                            @endif

                            <flux:button type="button" wire:click="deleteSale" wire:confirm="Are you sure you want to delete this sale? Inventory stock will be rearranged." variant="subtle" class="w-full text-rose-600">
                                <flux:icon.trash class="size-4 mr-2" />
                                Delete
                            </flux:button>
                        </div>
                        
                        <!-- Share PDF Actions Panel -->
                        <div x-show="sharePdfUrl" x-collapse class="mt-2 rounded-2xl bg-zinc-50 border border-zinc-100 p-3">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="flex size-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                        <flux:icon.check class="size-4" />
                                    </div>
                                    <span class="text-xs font-medium text-zinc-700">PDF Ready</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <flux:button type="button" size="sm" @click="sharePdfAction()" variant="primary">
                                        <flux:icon.share class="size-3 mr-1" />
                                        Share
                                    </flux:button>
                                    <flux:button as="a" x-bind:href="sharePdfUrl" x-bind:download="$refs.shareBillTitle?.innerText?.trim() + '.pdf'" variant="ghost" size="sm">
                                        <flux:icon.arrow-down-tray class="size-3" />
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                        <div x-show="sharePdfError" x-collapse class="mt-2 rounded-2xl bg-rose-50 border border-rose-100 p-3 text-xs text-rose-600">
                            Failed to generate PDF. Make sure you are using a modern browser.
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- 4. ITEMISED RETURNS DIALOG MODAL -->
    <div
        x-cloak
        x-show="retModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.self="retModalOpen = false; $wire.returnModalOpen = false"
    >
        <div
            class="w-full max-w-md bg-white rounded-3xl shadow-2xl p-5 max-h-[90%] flex flex-col"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 bg-zinc-50/20 -mx-5 px-5">
                <div>
                    <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Process Return / Exchange') }}</h3>
                    <p class="text-[10px] text-zinc-500">Invoice: {{ $this->selectedSale?->invoice_no }}</p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="$set('returnModalOpen', false)">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <!-- Return rows list -->
            <div class="flex-1 overflow-y-auto py-4 flex flex-col gap-3 scrollbar-none">
                @foreach ($returnItems as $prodId => $item)
                    <div class="rounded-2xl border border-zinc-100 p-3 bg-zinc-50/50 flex flex-col gap-2" wire:key="ret-item-{{ $prodId }}">
                        <div class="flex justify-between items-start text-xs">
                            <div>
                                <h4 class="font-bold text-zinc-900">{{ $item['name'] }}</h4>
                                <span class="text-[9px] text-zinc-400 uppercase font-mono mt-0.5">SKU: {{ $item['sku'] }}</span>
                            </div>
                            <span class="text-xs font-semibold text-zinc-500">Limit: {{ $item['max'] }}</span>
                        </div>

                        <!-- Qty Selector row -->
                        <div class="flex items-center justify-between border-t border-zinc-100 pt-2 text-xs">
                            <div class="flex items-center gap-1 bg-white rounded-xl border border-zinc-200 p-0.5">
                                <button type="button" wire:click="updateReturnQty({{ $prodId }}, {{ $item['quantity'] - 1 }})" class="size-5 rounded-lg flex items-center justify-center font-bold text-zinc-600">-</button>
                                <span class="px-2 font-bold text-zinc-900">{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="updateReturnQty({{ $prodId }}, {{ $item['quantity'] + 1 }})" class="size-5 rounded-lg flex items-center justify-center font-bold text-zinc-600">+</button>
                            </div>

                            <span class="font-bold text-zinc-950">Rs {{ number_format($item['subtotal'], 2) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Parameters form & submit -->
            <form wire:submit="submitReturn" class="border-t border-zinc-100 pt-3 flex flex-col gap-3">
                <flux:select wire:model="returnType" :label="__('Return Compensation Mode')">
                    <option value="cash_refund">Cash Refund (Withdraw cash)</option>
                    @if ($this->selectedSale?->due_amount > 0)
                        <option value="adjust_due">Reduce Invoice Due Account Balance</option>
                    @endif
                    <option value="exchange">Exchange Credit Note</option>
                </flux:select>

                <flux:textarea wire:model="returnNotes" :label="__('Ledger Return Notes')" rows="2" placeholder="Returned accessories." />

                <flux:button type="submit" variant="primary" class="w-full mt-1">
                    {{ __('Complete Return') }}
                </flux:button>
            </form>
        </div>
    </div>

    <!-- 5. PAY DUE BALANCE DIALOG MODAL -->
    <div
        x-cloak
        x-show="payDueOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.self="payDueOpen = false; $wire.payDueModalOpen = false"
    >
        <div
            class="w-full max-w-sm bg-white rounded-3xl shadow-2xl p-5"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 bg-zinc-50/20 -mx-5 px-5 mb-4">
                <div>
                    <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Pay Due Balance') }}</h3>
                    <p class="text-[10px] text-zinc-500">Invoice: {{ $this->selectedSale?->invoice_no }}</p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="$set('payDueModalOpen', false)">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <form wire:submit="submitPayDue" x-data="{ due: {{ $this->selectedSale?->due_amount ?? 0 }}, val: @entangle('payDueAmount') }" class="flex flex-col gap-4">
                <div class="flex flex-col gap-2 rounded-xl bg-orange-50 border border-orange-200 p-3 shadow-sm">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-semibold text-orange-800 uppercase tracking-wider">Original Due</span>
                        <span class="font-bold text-orange-700 text-sm">Rs {{ number_format($this->selectedSale?->due_amount ?? 0, 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center border-t border-orange-200/60 pt-2">
                        <span class="text-sm font-bold text-orange-900">Remaining Balance</span>
                        <span class="font-black text-orange-600 text-xl tracking-tight" x-text="'Rs ' + Math.max(0, due - (parseFloat(val) || 0)).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="date" wire:model="payDueDate" :label="__('Date')" required />
                    <flux:input type="number" step="0.01" wire:model="payDueAmount" @input="val = $event.target.value" :label="__('Amount (Rs)')" required />
                </div>

                <flux:select wire:model.live="payDueMethod" :label="__('Payment Mode')">
                    <option value="cash">Cash</option>
                    <option value="card">Card / POS</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </flux:select>

                @if ($payDueMethod === 'cheque')
                    <div class="rounded-2xl border border-amber-100 bg-amber-50/40 p-4">
                        <div class="flex items-center gap-2 text-amber-700">
                            <flux:icon.banknotes class="size-4" />
                            <p class="text-xs font-black uppercase tracking-wider">{{ __('Cheque Details') }}</p>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="payDueChequeBank" :label="__('Bank (optional)')" placeholder="Bank name" />
                            <flux:input wire:model="payDueChequeNo" :label="__('Cheque No (optional)')" placeholder="Cheque number" />
                        </div>
                        <div class="mt-3">
                            <flux:input wire:model="payDueChequeDate" :label="__('Cheque Date')" type="date" required />
                        </div>
                        <p class="mt-3 text-xs font-semibold text-amber-800">
                            {{ __('Cheque payments stay on hold until marked passed. If still pending 7 days after the cheque date, the system marks it passed automatically when POS opens.') }}
                        </p>
                    </div>
                @else
                    <flux:input wire:model="payDueReference" :label="__('Reference / Notes (Optional)')" placeholder="Txn ID or remarks..." />
                @endif

                <flux:button type="submit" variant="primary" class="w-full mt-2">
                    <flux:icon.banknotes class="size-4 mr-2" />
                    {{ __('Confirm Payment') }}
                </flux:button>
            </form>
        </div>
    </div>

    @if ($this->selectedSale)
        <?php
            $invoicePaperSize = Setting::get('invoice_paper_size', 'thermal_80mm');
            $devName = trim((string) config('app.dev_name', ''));
        ?>

        @if ($invoicePaperSize === 'A4')
            @include('partials.a4-invoice', ['sale' => $this->selectedSale, 'devName' => $devName])
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

                <div class="border-b border-dashed border-zinc-400 pb-2 mb-2 flex flex-col gap-0.5">
                    <p>Invoice: <span class="font-bold">{{ $this->selectedSale->invoice_no }}</span></p>
                    <p>Date: {{ $this->selectedSale->date->format('Y-m-d H:i') }}</p>
                    <p>Customer: {{ $this->selectedSale->customer?->name }}</p>
                    @if ($this->selectedSale->customer?->phone)
                        <p>Phone: {{ $this->selectedSale->customer?->phone }}</p>
                    @endif
                </div>

                <!-- Items Table -->
                <div class="border-b border-dashed border-zinc-400 pb-2 mb-2 flex flex-col gap-1.5">
                    @foreach ($this->selectedSale->items as $item)
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
                        <span>Rs {{ number_format($this->selectedSale->subtotal_amount, 2) }}</span>
                    </div>
                    @if ($this->selectedSale->discount_amount > 0)
                        <div class="flex justify-between">
                            <span>Discount</span>
                            <span>- Rs {{ number_format($this->selectedSale->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between font-bold text-xs border-t border-dashed border-zinc-400 pt-1">
                        <span>Grand Total</span>
                        <span>Rs {{ number_format($this->selectedSale->grand_total, 2) }}</span>
                    </div>

                    @if($this->selectedSale->payments->count() > 0)
                        <div class="flex flex-col gap-0.5 border-t border-dashed border-zinc-400 pt-1 mt-1 text-[9px] text-zinc-600">
                            @foreach($this->selectedSale->payments as $payment)
                                <div class="flex justify-between">
                                    <span>Payment ({{ $payment->date->format('y-m-d') }})</span>
                                    <span>Rs {{ number_format($payment->amount, 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex justify-between text-zinc-800 font-semibold border-t border-dashed border-zinc-400 pt-1 mt-1">
                        <span>Total Paid</span>
                        <span>Rs {{ number_format($this->selectedSale->paid_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between font-bold {{ $this->selectedSale->due_amount > 0 ? 'text-black' : 'text-zinc-600' }}">
                        <span>Due Balance</span>
                        <span>Rs {{ number_format($this->selectedSale->due_amount, 2) }}</span>
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
