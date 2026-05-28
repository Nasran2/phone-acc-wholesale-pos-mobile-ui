<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\TextItSmsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Manage Customers')] class extends Component
{
    use WithPagination;

    public string $search = '';

    // Customer Form state
    public ?int $customerId = null;
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public $opening_balance = 0.00;

    // Fast-action payment collection state
    public ?int $payingCustomerId = null;
    public $payAmount = 0.00;
    public string $payMethod = 'cash';
    public string $payReference = '';
    public string $payNotes = '';
    public string $payDate = '';
    public string $payChequeBank = '';
    public string $payChequeNo = '';
    public string $payChequeDate = '';

    // Detail ledger statement state
    public ?int $selectedCustomerId = null;

    // Invoice detail drill-down state
    public ?int $selectedSaleId = null;

    // Ledger pagination state (15 per page)
    public int $ledgerOrdersPage = 1;
    public int $ledgerPaymentsPage = 1;

    protected $queryString = ['search'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveCustomer(): void
    {
        $rules = [
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'required|numeric|min:0',
        ];

        $this->validate($rules);

        if ($this->customerId) {
            $customer = Customer::query()->findOrFail($this->customerId);
            $customer->update([
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
            ]);

            ActivityLogger::log('customer_update', "Updated customer details for {$this->name}.");
            Flux::toast(variant: 'success', text: __('Customer details updated.'));
        } else {
            $customer = Customer::query()->create([
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
                'opening_balance' => (float) $this->opening_balance,
                'due_balance' => (float) $this->opening_balance, // opening balance is initial due
            ]);

            ActivityLogger::log('customer_create', "Registered new customer: {$this->name}.");
            Flux::toast(variant: 'success', text: __('New customer registered.'));
        }

        $this->resetForm();
    }

    public function editCustomer(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        $this->customerId = $customer->id;
        $this->name = $customer->name;
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->opening_balance = (float) $customer->opening_balance;
    }

    public function deleteCustomer(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        if ($customer->sales()->count() > 0) {
            Flux::toast(variant: 'danger', text: __('Cannot remove customer: associated sale invoices exist.'));
            return;
        }

        ActivityLogger::log('customer_delete', "Deleted customer account: {$customer->name}.");
        $customer->delete();
        Flux::toast(variant: 'success', text: __('Customer deleted.'));
    }

    public function viewLedger(int $id): void
    {
        $this->selectedCustomerId = $id;
        $this->selectedSaleId = null;
        $this->ledgerOrdersPage = 1;
        $this->ledgerPaymentsPage = 1;
    }

    public function closeLedger(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedSaleId = null;
        $this->ledgerOrdersPage = 1;
        $this->ledgerPaymentsPage = 1;
    }

    public function viewSaleDetail(int $saleId): void
    {
        $this->selectedSaleId = $saleId;
    }

    public function closeSaleDetail(): void
    {
        $this->selectedSaleId = null;
    }

    public function nextLedgerOrdersPage(): void
    {
        $this->ledgerOrdersPage++;
    }

    public function prevLedgerOrdersPage(): void
    {
        $this->ledgerOrdersPage = max(1, $this->ledgerOrdersPage - 1);
    }

    public function nextLedgerPaymentsPage(): void
    {
        $this->ledgerPaymentsPage++;
    }

    public function prevLedgerPaymentsPage(): void
    {
        $this->ledgerPaymentsPage = max(1, $this->ledgerPaymentsPage - 1);
    }

    public function initiatePayment(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);
        $this->payingCustomerId = $customer->id;
        $this->payAmount = (float) $customer->due_balance;
        $this->payDate = date('Y-m-d');
        $this->payChequeDate = date('Y-m-d');
    }

    public function savePayment(TextItSmsService $smsService): void
    {
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payMethod' => 'required|in:cash,card,bank_transfer,qr,cheque',
            'payDate'   => 'required|date',
            'payReference' => 'nullable|string',
            'payNotes' => 'nullable|string',
            'payChequeBank' => 'nullable|string|max:100',
            'payChequeNo' => 'nullable|string|max:100',
            'payChequeDate' => 'required_if:payMethod,cheque|nullable|date',
        ]);

        $customer = Customer::query()->findOrFail($this->payingCustomerId);

        if ((float) $this->payAmount > $customer->due_balance) {
            Flux::toast(variant: 'danger', text: __('Payment amount exceeds total outstanding due.'));
            return;
        }

        // 1. Log polymorphic payment transaction
        $payment = $customer->payments()->create([
            'amount' => (float) $this->payAmount,
            'payment_method' => $this->payMethod,
            'date' => $this->payDate ?: date('Y-m-d'),
            'reference' => $this->payMethod === 'cheque' ? ($this->payChequeNo ?: $this->payReference) : $this->payReference,
            'cheque_bank' => $this->payMethod === 'cheque' ? ($this->payChequeBank ?: null) : null,
            'cheque_no' => $this->payMethod === 'cheque' ? ($this->payChequeNo ?: null) : null,
            'cheque_date' => $this->payMethod === 'cheque' ? $this->payChequeDate : null,
            'cheque_status' => $this->payMethod === 'cheque' ? 'pending' : null,
            'notes' => $this->payMethod === 'cheque'
                ? 'Cheque payment on hold until cleared.'
                : ($this->payNotes ?: 'Dues received from Customer Account Ledger.'),
        ]);

        // 2. Adjust customer outstanding due balance
        $oldDue = $customer->due_balance;
        $customer->decrement('due_balance', (float) $this->payAmount);
        $newDue = $customer->due_balance;

        ActivityLogger::log('payment_received', "Collected payment of Rs " . (float) $this->payAmount . " from Customer: {$customer->name}.");
        Flux::toast(variant: 'success', text: __('Payment captured successfully.'));

        // 3. Optional SMS Dispatch Alert
        if (Setting::get('sms_enabled') === '1' && ! empty($customer->phone) && $customer->phone !== '0000000000') {
            $template = Setting::get('sms_template_payment', 'Received Rs {paid} towards your account at {business_name}. Remaining due: Rs {due}.');
            $msg = $smsService->parseTemplate($template, [
                'customer_name' => $customer->name,
                'paid' => number_format((float) $this->payAmount, 2),
                'due' => number_format($newDue, 2),
            ]);
            $smsService->sendSms($customer->phone, $msg, 'PAY-' . $payment->id);
        }

        $this->resetPaymentForm();
    }

    public function resetPaymentForm(): void
    {
        $this->reset('payingCustomerId', 'payAmount', 'payMethod', 'payReference', 'payNotes', 'payDate', 'payChequeBank', 'payChequeNo', 'payChequeDate');
    }

    public function resetForm(): void
    {
        $this->reset('customerId', 'name', 'phone', 'email', 'address', 'opening_balance');
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->where(function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('phone', 'like', '%' . $this->search . '%');
            })
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    #[Computed]
    public function totalCustomers(): int
    {
        return (int) Customer::query()->count();
    }

    #[Computed]
    public function totalDueBalance(): float
    {
        return (float) Customer::query()->sum('due_balance');
    }

    #[Computed]
    public function customersWithDue(): int
    {
        return (int) Customer::query()->where('due_balance', '>', 0)->count();
    }

    #[Computed]
    public function selectedCustomer()
    {
        return $this->selectedCustomerId ? Customer::query()->findOrFail($this->selectedCustomerId) : null;
    }

    #[Computed]
    public function selectedSale()
    {
        return $this->selectedSaleId
            ? Sale::query()->with(['items.product', 'payments', 'customer'])->findOrFail($this->selectedSaleId)
            : null;
    }

    #[Computed]
    public function selectedCustomerLedger()
    {
        if (! $this->selectedCustomerId) {
            return collect();
        }

        $customer = Customer::query()->findOrFail($this->selectedCustomerId);

        $openingBalance = collect();
        if ((float) $customer->opening_balance > 0) {
            $openingBalance->push([
                'type' => 'opening',
                'badge' => __('Opening'),
                'id' => 'opening-' . $customer->id,
                'date' => $customer->created_at,
                'bill_date' => $customer->created_at,
                'paid_date' => null,
                'bill_no' => __('Opening Balance'),
                'ref' => __('Opening Balance'),
                'description' => __('Opening customer due balance'),
                'payment_method' => null,
                'payment_status' => __('due'),
                'debit' => (float) $customer->opening_balance,
                'credit' => 0.00,
                'raw_date' => $customer->created_at,
            ]);
        }

        $sales = Sale::query()
            ->with(['payments' => fn ($query) => $query->orderBy('date')->orderBy('id')])
            ->where('customer_id', $this->selectedCustomerId)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $salesMapped = $sales->map(fn ($sale) => [
                'type' => 'invoice',
                'badge' => __('Bill'),
                'id' => $sale->id,
                'date' => $sale->date,
                'bill_date' => $sale->date,
                'paid_date' => $sale->payments->max('date'),
                'bill_no' => $sale->invoice_no,
                'ref' => $sale->invoice_no,
                'description' => __('Bill created') . ' - ' . strtoupper((string) $sale->payment_status),
                'payment_method' => null,
                'payment_status' => $sale->payment_status,
                'debit' => (float) $sale->grand_total,
                'credit' => 0.00,
                'raw_date' => $sale->created_at,
            ]);

        $salePayments = $sales->flatMap(fn ($sale) => $sale->payments->map(fn ($pm) => [
                'type' => 'payment',
                'badge' => __('Payment'),
                'id' => $pm->id,
                'date' => $pm->date,
                'bill_date' => $sale->date,
                'paid_date' => $pm->date,
                'bill_no' => $sale->invoice_no,
                'ref' => $pm->reference ?: 'PAY-' . $pm->id,
                'description' => __('Payment received for bill') . ' ' . $sale->invoice_no,
                'payment_method' => $pm->payment_method,
                'payment_status' => $pm->cheque_status ?: __('paid'),
                'invoice_status' => $sale->payment_status,
                'invoice_due' => (float) $sale->due_amount,
                'debit' => 0.00,
                'credit' => (float) $pm->amount,
                'raw_date' => $pm->created_at,
            ]));

        $payments = Payment::query()
            ->where('paymentable_type', Customer::class)
            ->where('paymentable_id', $this->selectedCustomerId)
            ->get()
            ->map(fn ($pm) => [
                'type' => 'account_payment',
                'badge' => __('Account Payment'),
                'id' => $pm->id,
                'date' => $pm->date,
                'bill_date' => null,
                'paid_date' => $pm->date,
                'bill_no' => __('Account Ledger'),
                'ref' => $pm->reference ?: 'PAY-' . $pm->id,
                'description' => __('Account payment received'),
                'payment_method' => $pm->payment_method,
                'payment_status' => __('paid'),
                'debit' => 0.00,
                'credit' => (float) $pm->amount,
                'raw_date' => $pm->created_at,
            ]);

        $returns = \App\Models\SaleReturn::query()
            ->where('customer_id', $this->selectedCustomerId)
            ->get()
            ->map(fn ($ret) => [
                'type' => 'return',
                'badge' => __('Return'),
                'id' => $ret->id,
                'date' => $ret->date,
                'bill_date' => $ret->date,
                'paid_date' => null,
                'bill_no' => $ret->invoice_no,
                'ref' => $ret->invoice_no,
                'description' => __('Customer return') . ' (' . strtoupper((string) $ret->return_type) . ')',
                'payment_method' => null,
                'payment_status' => $ret->return_type,
                'debit' => 0.00,
                'credit' => (float) $ret->refund_amount + (float) $ret->adjusted_amount,
                'raw_date' => $ret->created_at,
            ]);

        return $openingBalance
            ->concat($salesMapped)
            ->concat($salePayments)
            ->concat($payments)
            ->concat($returns)
            ->sortBy('raw_date')
            ->values();
    }
}; ?>

<div class="flex flex-col gap-6" x-data="{
    ledgerOpen: @entangle('selectedCustomerId'),
    payOpen: @entangle('payingCustomerId'),
    saleOpen: @entangle('selectedSaleId'),
    editingCustomerId: @entangle('customerId'),
    mobileCustomerEditOpen: false,
    shareCopied: false,
    sharePreparing: false,
    sharePdfError: false,
    sharePdfFile: null,
    sharePdfUrl: null,
    ledgerTab: 'bills',
    init() {
        this.$watch('ledgerOpen', (val) => {
            if (!val) this.resetSharePdf();
        });
        this.$watch('editingCustomerId', (val) => {
            this.mobileCustomerEditOpen = !!val && window.innerWidth < 1024;
        });

        if (this.editingCustomerId && window.innerWidth < 1024) {
            this.mobileCustomerEditOpen = true;
        }
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
    async prepareLedgerPdf() {
        if (this.sharePreparing || this.sharePdfFile) return;

        const customerName = this.$refs.ledgerTitle?.innerText?.trim() || 'Ledger';
        const templateId = 'ledger-pdf-template';
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
            wrapper.style.width = '794px';
            wrapper.style.background = 'white';
            wrapper.style.zIndex = '-1';

            const clonedEl = originalEl.cloneNode(true);
            clonedEl.classList.remove('hidden', 'print:block');
            clonedEl.style.display = 'block';
            clonedEl.style.width = '794px';
            clonedEl.style.margin = '0';
            clonedEl.style.padding = '20px';

            wrapper.appendChild(clonedEl);
            document.body.appendChild(wrapper);

            const canvas = await html2canvas(clonedEl, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
                logging: false,
                windowWidth: 794,
                width: 794
            });

            document.body.removeChild(wrapper);

            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const pdf = new window.jsPDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });

            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = (canvas.height * pdfWidth) / canvas.width;

            pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);

            const pdfBlob = pdf.output('blob');
            this.sharePdfFile = new File([pdfBlob], `${customerName}-Ledger.pdf`, { type: 'application/pdf' });
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
                    title: 'Customer Ledger Statement',
                    text: 'Please find your account ledger statement attached.',
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
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Customers Directory') }}</h1>
            <p class="text-sm text-zinc-500">{{ __('Create and modify customer profiles, record outstanding receivable collections, and view transaction statements.') }}</p>
        </div>
    </div>

    <section class="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-3">
        <div class="app-card p-3 sm:p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 sm:h-10 sm:w-10">
                    <flux:icon.user-group class="size-4 sm:size-5" />
                </div>
                <flux:badge size="sm" color="zinc">{{ __('Customers') }}</flux:badge>
            </div>
            <p class="mt-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 sm:mt-4 sm:text-xs">{{ __('Total Profiles') }}</p>
            <p class="mt-1 font-display text-lg font-bold leading-tight text-zinc-950 sm:text-xl">{{ number_format($this->totalCustomers) }}</p>
        </div>
        <div class="app-card p-3 sm:p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 sm:h-10 sm:w-10">
                    <flux:icon.exclamation-triangle class="size-4 sm:size-5" />
                </div>
                <flux:badge size="sm" color="rose">{{ __('Due') }}</flux:badge>
            </div>
            <p class="mt-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 sm:mt-4 sm:text-xs">{{ __('Outstanding Receivables') }}</p>
            <p class="mt-1 font-display text-lg font-bold leading-tight text-zinc-950 sm:text-xl">Rs {{ number_format($this->totalDueBalance, 2) }}</p>
        </div>
        <div class="app-card p-3 sm:p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 sm:h-10 sm:w-10">
                    <flux:icon.banknotes class="size-4 sm:size-5" />
                </div>
                <flux:badge size="sm" color="emerald">{{ __('Active') }}</flux:badge>
            </div>
            <p class="mt-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 sm:mt-4 sm:text-xs">{{ __('Customers With Dues') }}</p>
            <p class="mt-1 font-display text-lg font-bold leading-tight text-zinc-950 sm:text-xl">{{ number_format($this->customersWithDue) }}</p>
        </div>
    </section>

    <!-- Main Section Grid -->
    <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
        <!-- 1. Customer Form Card -->
        <div
            class="app-card p-5 h-fit scroll-mt-24"
            x-data="{
                showForm: window.innerWidth >= 1024,
                editingId: @entangle('customerId'),
                revealForm() {
                    if (window.innerWidth < 1024) {
                        return;
                    }

                    this.showForm = true;

                    this.$nextTick(() => {
                        this.$el.querySelector('input')?.focus({ preventScroll: true });
                    });
                },
            }"
            x-init="$watch('editingId', (value) => { if (value) revealForm() }); if (editingId) revealForm()"
        >
            <div
                class="flex items-center justify-between border-b border-zinc-100 pb-4 cursor-pointer group"
                @click="showForm = !showForm"
            >
                <div class="flex flex-col gap-1">
                    <h3 class="font-display text-base font-semibold text-zinc-900">
                        {{ $customerId ? __('Edit Customer Profile') : __('Add Customer Profile') }}
                    </h3>
                    <p class="text-xs text-zinc-500">{{ __('Save key details to dispatch automatic invoice dispatches via SMS.') }}</p>
                </div>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-50 group-hover:bg-zinc-100 transition shrink-0">
                    <flux:icon.chevron-down class="size-4 text-zinc-500 transition-transform duration-300" x-bind:class="showForm ? 'rotate-180' : ''" />
                </div>
            </div>

            <div x-show="showForm" x-collapse>
                <form wire:submit="saveCustomer" class="mt-4 flex flex-col gap-4">
                    <flux:input wire:model="name" :label="__('Customer Name')" required />
                    <flux:input wire:model="phone" :label="__('Phone Number')" placeholder="e.g. 0771234567" />
                    <flux:input wire:model="email" :label="__('Email Address')" type="email" placeholder="e.g. nasran@example.com" />
                    <flux:textarea wire:model="address" :label="__('Billing Address')" rows="2" />

                    @if (! $customerId)
                        <flux:input wire:model="opening_balance" :label="__('Opening Dues (Initial Outstanding Receivable)')" type="number" step="0.01" />
                    @endif

                    <div class="mt-4 flex gap-2">
                        <flux:button type="submit" variant="primary" class="flex-1">
                            {{ $customerId ? __('Update Customer') : __('Register Customer') }}
                        </flux:button>
                        @if ($customerId)
                            <flux:button type="button" wire:click="resetForm" @click="if(window.innerWidth < 1024) showForm = false" variant="ghost">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <!-- 2. Interactive Customers List Grid -->
        <div class="flex flex-col gap-4">
            <div class="app-card p-4 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input
                        wire:model.live.debounce.500ms="search"
                        type="text"
                        placeholder="Search customers by name or phone..."
                        class="w-full bg-transparent text-sm text-zinc-950 focus:outline-none"
                    />
                </div>
            </div>

            <div class="grid gap-3">
                @foreach ($this->customers as $c)
                    <div class="app-card p-4 flex flex-col gap-3 justify-between sm:flex-row sm:items-center" wire:key="customer-item-{{ $c->id }}">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full bg-violet-50 text-violet-600 font-semibold">
                                {{ Str::substr($c->name, 0, 2) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-zinc-900">{{ $c->name }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    @if ($c->phone)
                                        <flux:badge size="sm" color="zinc">
                                            <flux:icon.phone class="size-3 mr-1 text-zinc-500" />
                                            {{ $c->phone }}
                                        </flux:badge>
                                    @endif
                                    @if ($c->address)
                                        <span class="text-xs text-zinc-500 max-w-[200px] truncate">
                                            {{ $c->address }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Actions / Account Info -->
                        <div class="flex items-center justify-between border-t border-zinc-100 pt-3 sm:border-t-0 sm:pt-0 sm:gap-4">
                            <div class="flex flex-col sm:items-end">
                                <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">{{ __('Dues Balance') }}</span>
                                <span class="text-sm font-bold {{ $c->due_balance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                    Rs {{ number_format($c->due_balance, 2) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1">
                                <flux:button variant="ghost" size="sm" wire:click="viewLedger({{ $c->id }})">
                                    Ledger
                                </flux:button>

                                @if ($c->due_balance > 0)
                                    <flux:button variant="ghost" size="sm" class="text-emerald-600 hover:text-emerald-700" wire:click="initiatePayment({{ $c->id }})">
                                        Collect
                                    </flux:button>
                                @endif

                                @if ($c->phone !== '0000000000')
                                    <flux:button variant="ghost" size="sm" wire:click="editCustomer({{ $c->id }})">
                                        Edit
                                    </flux:button>
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-rose-500 px-2 py-1 hover:underline"
                                        x-on:click.prevent="if(confirm('Remove this customer record?')) { $wire.deleteCustomer({{ $c->id }}) }"
                                    >
                                        Delete
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2">
                {{ $this->customers->links() }}
            </div>
        </div>
    </div>

    <!-- 3. MOBILE CUSTOMER EDIT POPUP -->
    <div
        x-cloak
        x-show="mobileCustomerEditOpen"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4 transition-opacity backdrop-blur-sm lg:hidden"
        @click.self="mobileCustomerEditOpen = false; $wire.resetForm()"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <form
            wire:submit="saveCustomer"
            class="w-full max-w-md rounded-[2rem] bg-white p-5 shadow-2xl"
            x-transition:enter="ease-out duration-200 transform"
            x-transition:enter-start="translate-y-4 scale-95"
            x-transition:enter-end="translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150 transform"
            x-transition:leave-start="translate-y-0 scale-100"
            x-transition:leave-end="translate-y-4 scale-95"
        >
            <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-violet-500">{{ __('Customer profile') }}</p>
                    <h3 class="mt-1 text-lg font-black text-zinc-950">{{ __('Edit Customer') }}</h3>
                </div>
                <button
                    type="button"
                    wire:click="resetForm"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-zinc-200 text-zinc-500 transition hover:bg-zinc-50"
                >
                    <flux:icon.x-mark class="size-4" />
                </button>
            </div>

            <div class="mt-4 grid gap-3">
                <flux:input wire:model="name" :label="__('Customer Name')" required />
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="phone" :label="__('Phone')" placeholder="0771234567" />
                    <flux:input wire:model="email" :label="__('Email')" type="email" />
                </div>
                <flux:textarea wire:model="address" :label="__('Billing Address')" rows="2" />
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2">
                <flux:button type="button" wire:click="resetForm" variant="ghost" class="w-full">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="w-full bg-zinc-950!">
                    {{ __('Update') }}
                </flux:button>
            </div>
        </form>
    </div>

    <!-- 3. CUSTOMER LEDGER STATEMENT DRAWER / MODAL -->
    <div
        x-cloak
        x-show="ledgerOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        @click.self="ledgerOpen = null; $wire.closeLedger()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-5xl bg-white rounded-3xl shadow-2xl flex flex-col overflow-hidden max-h-[95%]"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <!-- Drawer Header -->
            <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                <div>
                    <h3 class="font-display font-bold text-zinc-950" x-ref="ledgerTitle">
                        {{ $this->selectedCustomer?->name }}
                    </h3>
                    <p class="text-xs text-zinc-500 mt-1">{{ __('Account Statement Ledger Dues Log') }}</p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="closeLedger">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <!-- Ledger Rows Scroll List -->
            <div class="flex-1 overflow-y-auto p-5 scrollbar-none flex flex-col gap-4">
                <!-- Customer Quick Info Card -->
                <div class="grid grid-cols-2 gap-4 rounded-2xl bg-zinc-50 border border-zinc-100 p-4">
                    <div>
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider block">{{ __('Outstanding Receivables') }}</span>
                        <span class="text-lg font-bold text-zinc-900">
                            Rs {{ number_format($this->selectedCustomer?->due_balance ?? 0, 2) }}
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider block">{{ __('Opening Balance') }}</span>
                        <span class="text-lg font-semibold text-zinc-600">
                            Rs {{ number_format($this->selectedCustomer?->opening_balance ?? 0, 2) }}
                        </span>
                    </div>
                </div>

                @php
                    $ledgerRows = $this->selectedCustomerLedger;
                    $ledgerOrders = $ledgerRows->filter(fn ($row) => in_array($row['type'], ['invoice', 'opening', 'return'], true));
                    $ledgerPayments = $ledgerRows->filter(fn ($row) => in_array($row['type'], ['payment', 'account_payment'], true));

                    $ordersPerPage = 15;
                    $paymentsPerPage = 15;
                    $ordersTotalPages = max(1, (int) ceil($ledgerOrders->count() / $ordersPerPage));
                    $paymentsTotalPages = max(1, (int) ceil($ledgerPayments->count() / $paymentsPerPage));

                    $ordersPage = min($this->ledgerOrdersPage, $ordersTotalPages);
                    $paymentsPage = min($this->ledgerPaymentsPage, $paymentsTotalPages);

                    $ledgerOrdersPageRows = $ledgerOrders->forPage($ordersPage, $ordersPerPage);
                    $ledgerPaymentsPageRows = $ledgerPayments->forPage($paymentsPage, $paymentsPerPage);
                @endphp

                <!-- Ledger Timeline -->
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Bills & Payment Timeline') }}</h4>
                        <span class="text-[11px] font-semibold text-zinc-400">{{ __('Bill no, bill date, and paid date') }}</span>
                    </div>

                    <div class="flex items-center gap-2 lg:hidden">
                        <button type="button" class="flex-1 rounded-lg px-3 py-2 text-xs font-black uppercase tracking-wider border transition"
                            :class="ledgerTab === 'bills' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-zinc-500 border-zinc-200'"
                            @click="ledgerTab = 'bills'">
                            {{ __('Bills') }}
                        </button>
                        <button type="button" class="flex-1 rounded-lg px-3 py-2 text-xs font-black uppercase tracking-wider border transition"
                            :class="ledgerTab === 'payments' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-zinc-500 border-zinc-200'"
                            @click="ledgerTab = 'payments'">
                            {{ __('Payments') }}
                        </button>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="flex flex-col gap-3" x-show="ledgerTab === 'bills' || window.innerWidth >= 1024" x-cloak>
                            <div class="flex items-center justify-between">
                                <h5 class="text-xs font-black uppercase tracking-wider text-zinc-500">{{ __('Orders / Bills') }}</h5>
                                <span class="text-[11px] font-semibold text-zinc-400">{{ $ordersPage }} / {{ $ordersTotalPages }}</span>
                            </div>

                            @forelse ($ledgerOrdersPageRows as $row)
                                <button type="button"
                                    class="text-left rounded-2xl border border-zinc-100 p-4 transition hover:bg-zinc-50/50"
                                    wire:key="customer-ledger-order-{{ $row['type'] }}-{{ $row['id'] }}"
                                    @if ($row['type'] === 'invoice')
                                        wire:click="viewSaleDetail({{ $row['id'] }})"
                                    @endif
                                >
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <flux:badge size="sm" :color="$row['type'] === 'opening' ? 'zinc' : ($row['type'] === 'return' ? 'amber' : 'violet')">
                                                    {{ $row['badge'] }}
                                                </flux:badge>
                                                <span class="text-xs font-semibold text-zinc-400">{{ __('Bill No') }}: {{ $row['bill_no'] }}</span>
                                            </div>
                                            <h6 class="mt-2 text-sm font-bold text-zinc-900">{{ $row['description'] }}</h6>
                                            <div class="mt-2 grid gap-2 text-[11px] font-semibold text-zinc-500 sm:grid-cols-3">
                                                @if ($row['bill_date'])
                                                    <span>{{ __('Bill Date') }}: {{ $row['bill_date']->format('Y-m-d') }}</span>
                                                @endif
                                                @if ($row['paid_date'])
                                                    <span>{{ __('Paid Date') }}: {{ $row['paid_date']->format('Y-m-d') }}</span>
                                                @endif
                                                @if ($row['payment_method'])
                                                    <span>{{ __('Method') }}: {{ strtoupper((string) $row['payment_method']) }}</span>
                                                @endif
                                            </div>
                                            @if ($row['ref'] && $row['ref'] !== $row['bill_no'])
                                                <p class="mt-1 text-[11px] text-zinc-400">{{ __('Reference') }}: {{ $row['ref'] }}</p>
                                            @endif
                                        </div>
                                        <div class="shrink-0 text-left sm:text-right">
                                            <span class="text-sm font-bold text-rose-600">+ Rs {{ number_format($row['debit'], 2) }}</span>
                                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $row['payment_status'] }}</p>
                                        </div>
                                    </div>
                                </button>
                            @empty
                                <div class="py-10 text-center text-xs text-zinc-400">
                                    {{ __('No bills logged for this customer account.') }}
                                </div>
                            @endforelse

                            @if ($ordersTotalPages > 1)
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <flux:button type="button" variant="ghost" icon="chevron-left" wire:click="prevLedgerOrdersPage" :disabled="$ordersPage <= 1">
                                        {{ __('Prev') }}
                                    </flux:button>
                                    <span class="text-[11px] font-semibold text-zinc-400">{{ __('Page') }} {{ $ordersPage }}</span>
                                    <flux:button type="button" variant="ghost" icon="chevron-right" wire:click="nextLedgerOrdersPage" :disabled="$ordersPage >= $ordersTotalPages">
                                        {{ __('Next') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col gap-3" x-show="ledgerTab === 'payments' || window.innerWidth >= 1024" x-cloak>
                            <div class="flex items-center justify-between">
                                <h5 class="text-xs font-black uppercase tracking-wider text-zinc-500">{{ __('Payments') }}</h5>
                                <span class="text-[11px] font-semibold text-zinc-400">{{ $paymentsPage }} / {{ $paymentsTotalPages }}</span>
                            </div>

                            @forelse ($ledgerPaymentsPageRows as $row)
                                <div class="rounded-2xl border border-zinc-100 p-4 transition hover:bg-zinc-50/50" wire:key="customer-ledger-payment-{{ $row['id'] }}">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                @php
                                                    $invoiceStatus = $row['invoice_status'] ?? null;
                                                    $invoiceDue = (float) ($row['invoice_due'] ?? 0);
                                                    $paymentTone = $invoiceStatus === 'paid' ? 'emerald' : ($invoiceStatus ? 'rose' : 'zinc');
                                                @endphp
                                                <flux:badge size="sm" :color="$paymentTone">{{ $row['badge'] }}</flux:badge>
                                                <span class="text-xs font-semibold text-zinc-400">{{ __('Bill No') }}: {{ $row['bill_no'] }}</span>
                                            </div>
                                            <h6 class="mt-2 text-sm font-bold text-zinc-900">{{ $row['description'] }}</h6>
                                            <div class="mt-2 grid gap-2 text-[11px] font-semibold text-zinc-500 sm:grid-cols-3">
                                                @if ($row['bill_date'])
                                                    <span>{{ __('Bill Date') }}: {{ $row['bill_date']->format('Y-m-d') }}</span>
                                                @endif
                                                @if ($row['paid_date'])
                                                    <span>{{ __('Paid Date') }}: {{ $row['paid_date']->format('Y-m-d') }}</span>
                                                @endif
                                                @if ($row['payment_method'])
                                                    <span>{{ __('Method') }}: {{ strtoupper((string) $row['payment_method']) }}</span>
                                                @endif
                                            </div>
                                            @if ($row['ref'] && $row['ref'] !== $row['bill_no'])
                                                <p class="mt-1 text-[11px] text-zinc-400">{{ __('Reference') }}: {{ $row['ref'] }}</p>
                                            @endif
                                            @if ($invoiceStatus)
                                                <p class="mt-1 text-[11px] font-semibold text-zinc-500">
                                                    {{ __('Invoice Status') }}: {{ strtoupper((string) $invoiceStatus) }}
                                                    @if ($invoiceDue > 0)
                                                        · {{ __('Due') }}: Rs {{ number_format($invoiceDue, 2) }}
                                                    @endif
                                                </p>
                                            @endif
                                        </div>
                                        <div class="shrink-0 text-left sm:text-right">
                                            <span class="text-sm font-bold text-emerald-600">- Rs {{ number_format($row['credit'], 2) }}</span>
                                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $invoiceStatus ? strtoupper((string) $invoiceStatus) : $row['payment_status'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="py-10 text-center text-xs text-zinc-400">
                                    {{ __('No payments logged for this customer account.') }}
                                </div>
                            @endforelse

                            @if ($paymentsTotalPages > 1)
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <flux:button type="button" variant="ghost" icon="chevron-left" wire:click="prevLedgerPaymentsPage" :disabled="$paymentsPage <= 1">
                                        {{ __('Prev') }}
                                    </flux:button>
                                    <span class="text-[11px] font-semibold text-zinc-400">{{ __('Page') }} {{ $paymentsPage }}</span>
                                    <flux:button type="button" variant="ghost" icon="chevron-right" wire:click="nextLedgerPaymentsPage" :disabled="$paymentsPage >= $paymentsTotalPages">
                                        {{ __('Next') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($ledgerOrders->isEmpty() && $ledgerPayments->isEmpty())
                        <div class="py-10 text-center text-xs text-zinc-400">
                            {{ __('No bills or payments logged for this customer account.') }}
                        </div>
                    @endif
                </div>

                <!-- Share & Print Actions -->
                <div class="border-t border-zinc-100 pt-4 mt-2">
                    <div class="grid grid-cols-2 gap-2">
                        <flux:button type="button" onclick="window.print()" variant="outline" class="w-full text-zinc-600">
                            <flux:icon.printer class="size-4 mr-2" />
                            Print Ledger
                        </flux:button>

                        <flux:button type="button" @click="prepareLedgerPdf()" variant="outline" class="w-full text-zinc-600">
                            <template x-if="sharePreparing">
                                <flux:icon.arrow-path class="size-4 mr-2 animate-spin" />
                            </template>
                            <template x-if="!sharePreparing">
                                <flux:icon.share class="size-4 mr-2" />
                            </template>
                            <span x-text="sharePreparing ? 'Preparing...' : 'Share PDF'"></span>
                        </flux:button>
                    </div>

                    <!-- Share PDF Actions Panel -->
                    <div x-show="sharePdfUrl" x-collapse class="mt-3 rounded-2xl bg-zinc-50 border border-zinc-100 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <div class="flex size-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                                    <flux:icon.check class="size-4" />
                                </div>
                                <span class="text-xs font-medium text-zinc-700">PDF Statement Ready</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button type="button" size="sm" @click="sharePdfAction()" variant="primary">
                                    <flux:icon.share class="size-3 mr-1" />
                                    Share
                                </flux:button>
                                <flux:button as="a" x-bind:href="sharePdfUrl" x-bind:download="$refs.ledgerTitle?.innerText?.trim() + '-Ledger.pdf'" variant="ghost" size="sm">
                                    <flux:icon.arrow-down-tray class="size-3" />
                                </flux:button>
                            </div>
                        </div>
                    </div>

                    <div x-show="sharePdfError" x-collapse class="mt-3 rounded-2xl bg-rose-50 border border-rose-100 p-3 text-xs text-rose-600">
                        Failed to generate PDF. Make sure you are using a modern browser.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. RECEIVE DUE COLLECTION DRAWER -->
    <div
        x-cloak
        x-show="payOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        @click.self="payOpen = null; $wire.resetPaymentForm()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-lg bg-white rounded-3xl shadow-2xl overflow-hidden"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 bg-zinc-50/60">
                <div>
                    <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Capture Outstanding Dues') }}</h3>
                    @if($this->payingCustomerId)
                        <p class="text-xs text-zinc-500 mt-0.5">{{ Customer::query()->find($this->payingCustomerId)?->name }}</p>
                    @endif
                </div>
                <flux:button variant="ghost" size="sm" wire:click="resetPaymentForm">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            @php
                $payCustomer = $this->payingCustomerId ? Customer::query()->find($this->payingCustomerId) : null;
                $currentDue  = (float) ($payCustomer?->due_balance ?? 0);
                $afterPay    = max(0, $currentDue - (float) $this->payAmount);
            @endphp

            {{-- Balance Summary Banner --}}
            <div class="grid grid-cols-2 divide-x divide-zinc-100 border-b border-zinc-100">
                <div class="px-6 py-4">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Current Balance') }}</p>
                    <p class="mt-1 text-xl font-bold {{ $currentDue > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                        Rs {{ number_format($currentDue, 2) }}
                    </p>
                    <p class="text-[10px] text-zinc-400 mt-0.5">{{ __('Total outstanding due') }}</p>
                </div>
                <div class="px-6 py-4">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Balance After Payment') }}</p>
                    <p class="mt-1 text-xl font-bold {{ $afterPay > 0 ? 'text-amber-500' : 'text-emerald-600' }}">
                        Rs {{ number_format($afterPay, 2) }}
                    </p>
                    <p class="text-[10px] text-zinc-400 mt-0.5">{{ $afterPay <= 0 ? __('Fully cleared') : __('Remaining after this payment') }}</p>
                </div>
            </div>

            <form wire:submit="savePayment" class="p-6 flex flex-col gap-4">

                {{-- Payment Date --}}
                <flux:input
                    wire:model="payDate"
                    :label="__('Payment Date')"
                    type="date"
                    required
                />

                {{-- Amount --}}
                <flux:input
                    wire:model.live="payAmount"
                    :label="__('Collection Amount Received (Rs)')"
                    type="number"
                    step="0.01"
                    min="0.01"
                    :max="$currentDue"
                    required
                />

                {{-- Method --}}
                <flux:select wire:model.live="payMethod" :label="__('Payment Method')">
                    <option value="cash">Cash Account</option>
                    <option value="card">Credit / Debit Card</option>
                    <option value="bank_transfer">Direct Bank Deposit</option>
                    <option value="qr">LankaQR / QR Scan</option>
                    <option value="cheque">Cheque Payment Hold</option>
                </flux:select>

                @if ($payMethod === 'cheque')
                    <div class="rounded-2xl border border-amber-100 bg-amber-50/40 p-4">
                        <div class="flex items-center gap-2 text-amber-700">
                            <flux:icon.banknotes class="size-4" />
                            <p class="text-xs font-black uppercase tracking-wider">{{ __('Cheque Details') }}</p>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="payChequeBank" :label="__('Bank (optional)')" placeholder="Bank name" />
                            <flux:input wire:model="payChequeNo" :label="__('Cheque No (optional)')" placeholder="Cheque number" />
                        </div>
                        <div class="mt-3">
                            <flux:input wire:model="payChequeDate" :label="__('Cheque Date')" type="date" required />
                        </div>
                        <p class="mt-3 text-xs font-semibold text-amber-800">
                            {{ __('Cheque payments stay on hold until marked passed. If still pending 7 days after the cheque date, the system marks it passed automatically when POS opens.') }}
                        </p>
                    </div>
                @else
                    {{-- Reference --}}
                    <flux:input wire:model="payReference" :label="__('Transaction Reference (e.g. Cheque / TxID)')" />
                @endif

                {{-- Notes --}}
                <flux:textarea wire:model="payNotes" :label="__('Payment Ledger Notes')" rows="2" placeholder="Dues collection payment." />

                <div class="flex gap-3 mt-2">
                    <flux:button type="submit" variant="primary" class="flex-1">
                        <flux:icon.check class="size-4 mr-1" />
                        {{ __('Record Payment Receipt') }}
                    </flux:button>
                    <flux:button type="button" variant="outline" wire:click="resetPaymentForm">
                        {{ __('Cancel') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4.5 SALE / BILL DETAIL MODAL -->
    <div
        x-cloak
        x-show="saleOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        @click.self="saleOpen = null; $wire.closeSaleDetail()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-3xl bg-white rounded-3xl shadow-2xl flex flex-col overflow-hidden max-h-[95%]"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                <div class="min-w-0">
                    <h3 class="font-display font-bold text-zinc-950 truncate">
                        {{ __('Bill Details') }}: {{ $this->selectedSale?->invoice_no }}
                    </h3>
                    <p class="text-xs text-zinc-500 mt-1">
                        {{ __('Date') }}: {{ $this->selectedSale?->date?->format('Y-m-d') ?? $this->selectedSale?->date }}
                        @if ($this->selectedSale?->payment_status)
                            · {{ __('Status') }}: {{ strtoupper((string) $this->selectedSale->payment_status) }}
                        @endif
                    </p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="closeSaleDetail">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <div class="flex-1 overflow-y-auto p-5 scrollbar-none flex flex-col gap-4">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Total') }}</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900">Rs {{ number_format((float) ($this->selectedSale?->grand_total ?? 0), 2) }}</p>
                    </div>
                    <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Paid') }}</p>
                        <p class="mt-1 text-lg font-bold text-emerald-600">Rs {{ number_format((float) ($this->selectedSale?->paid_amount ?? 0), 2) }}</p>
                    </div>
                    <div class="rounded-2xl border border-zinc-100 bg-white p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Due') }}</p>
                        <p class="mt-1 text-lg font-bold text-rose-600">Rs {{ number_format((float) ($this->selectedSale?->due_amount ?? 0), 2) }}</p>
                    </div>
                </div>

                <div class="app-card p-0 overflow-hidden">
                    <div class="border-b border-zinc-100 p-4">
                        <h4 class="text-xs font-black uppercase tracking-wider text-zinc-500">{{ __('Products') }}</h4>
                    </div>
                    <div class="hidden md:block">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 text-zinc-400">
                                <tr>
                                    <th class="px-4 py-3 font-bold uppercase tracking-wider">{{ __('Product') }}</th>
                                    <th class="px-4 py-3 font-bold uppercase tracking-wider text-right">{{ __('Qty') }}</th>
                                    <th class="px-4 py-3 font-bold uppercase tracking-wider text-right">{{ __('Price') }}</th>
                                    <th class="px-4 py-3 font-bold uppercase tracking-wider text-right">{{ __('Subtotal') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700">
                                @forelse ($this->selectedSale?->items ?? [] as $item)
                                    <tr wire:key="sale-item-{{ $item->id }}">
                                        <td class="px-4 py-3.5">{{ $item->product?->name ?? __('Product') }}</td>
                                        <td class="px-4 py-3.5 text-right">{{ $item->quantity }}</td>
                                        <td class="px-4 py-3.5 text-right">Rs {{ number_format((float) $item->selling_price, 2) }}</td>
                                        <td class="px-4 py-3.5 text-right">Rs {{ number_format((float) $item->subtotal, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-zinc-400">{{ __('No products found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="grid gap-3 p-3 md:hidden">
                        @forelse ($this->selectedSale?->items ?? [] as $item)
                            <div class="rounded-2xl border border-zinc-100 p-4" wire:key="sale-item-card-{{ $item->id }}">
                                <p class="text-sm font-bold text-zinc-900">{{ $item->product?->name ?? __('Product') }}</p>
                                <div class="mt-2 grid grid-cols-3 gap-2 text-[11px] font-semibold text-zinc-500">
                                    <span>{{ __('Qty') }}: {{ $item->quantity }}</span>
                                    <span class="text-right col-span-2">{{ __('Price') }}: Rs {{ number_format((float) $item->selling_price, 2) }}</span>
                                </div>
                                <p class="mt-2 text-sm font-black text-zinc-900">{{ __('Subtotal') }}: Rs {{ number_format((float) $item->subtotal, 2) }}</p>
                            </div>
                        @empty
                            <div class="py-10 text-center text-xs text-zinc-400">{{ __('No products found.') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="app-card p-0 overflow-hidden">
                    <div class="border-b border-zinc-100 p-4">
                        <h4 class="text-xs font-black uppercase tracking-wider text-zinc-500">{{ __('Payments') }}</h4>
                    </div>
                    <div class="divide-y divide-zinc-100">
                        @forelse ($this->selectedSale?->payments ?? [] as $pm)
                            <div class="p-4 flex items-start justify-between gap-4" wire:key="sale-payment-{{ $pm->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-zinc-900">{{ strtoupper((string) $pm->payment_method) }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ __('Date') }}: {{ $pm->date?->format('Y-m-d') ?? $pm->date }}
                                        @if ($pm->reference) · {{ __('Ref') }}: {{ $pm->reference }} @endif
                                    </p>
                                </div>
                                <p class="shrink-0 text-sm font-black text-emerald-600">Rs {{ number_format((float) $pm->amount, 2) }}</p>
                            </div>
                        @empty
                            <div class="p-8 text-center text-sm text-zinc-400">{{ __('No payments recorded for this bill.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. HIDDEN PRINTABLE LEDGER TEMPLATE -->
    <div id="ledger-pdf-template" class="hidden print:block bg-white p-8 font-sans">
    <div class="flex items-center justify-between border-b-2 border-zinc-900 pb-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-zinc-900">{{ App\Models\Setting::get('business_name', 'Business Name') }}</h1>
            <p class="text-zinc-600 mt-1">{{ App\Models\Setting::get('business_address', '') }}</p>
            <p class="text-zinc-600">{{ App\Models\Setting::get('business_phone', '') }}</p>
        </div>
        <div class="text-right">
            <h2 class="text-2xl font-black text-zinc-400 uppercase tracking-widest">Account Ledger</h2>
            <p class="text-zinc-500 mt-1">Generated: {{ date('Y-m-d H:i') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-8 mb-8 border border-zinc-200 rounded-xl p-6 bg-zinc-50">
        <div>
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider mb-2">Customer Details</h3>
            <p class="text-xl font-bold text-zinc-900">{{ $this->selectedCustomer?->name }}</p>
            @if($this->selectedCustomer?->phone)
                <p class="text-zinc-600">{{ $this->selectedCustomer->phone }}</p>
            @endif
            @if($this->selectedCustomer?->address)
                <p class="text-zinc-600">{{ $this->selectedCustomer->address }}</p>
            @endif
        </div>
        <div class="text-right flex flex-col justify-center">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider mb-2">Account Summary</h3>
            <p class="text-zinc-600">Opening Balance: Rs {{ number_format($this->selectedCustomer?->opening_balance ?? 0, 2) }}</p>
            <p class="text-lg font-bold text-zinc-900 mt-1">
                Outstanding Balance: <span class="{{ ($this->selectedCustomer?->due_balance ?? 0) > 0 ? 'text-rose-600' : 'text-emerald-600' }}">Rs {{ number_format($this->selectedCustomer?->due_balance ?? 0, 2) }}</span>
            </p>
        </div>
    </div>

    <table class="w-full text-left text-sm">
        <thead>
            <tr class="border-b-2 border-zinc-200">
                <th class="py-3 px-2 text-zinc-500 font-bold w-28">Bill Date</th>
                <th class="py-3 px-2 text-zinc-500 font-bold w-28">Paid Date</th>
                <th class="py-3 px-2 text-zinc-500 font-bold w-32">Bill No</th>
                <th class="py-3 px-2 text-zinc-500 font-bold">Description</th>
                <th class="py-3 px-2 text-zinc-500 font-bold text-right w-32">Debit (+)</th>
                <th class="py-3 px-2 text-zinc-500 font-bold text-right w-32">Credit (-)</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
            @forelse($this->selectedCustomerLedger as $row)
                <tr>
                    <td class="py-3 px-2 whitespace-nowrap">{{ $row['bill_date']?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 px-2 whitespace-nowrap">{{ $row['paid_date']?->format('Y-m-d') ?? '-' }}</td>
                    <td class="py-3 px-2 font-medium">{{ $row['bill_no'] }}</td>
                    <td class="py-3 px-2 text-zinc-600">
                        {{ $row['description'] }}
                        @if ($row['payment_method'])
                            <span class="text-zinc-400">({{ strtoupper((string) $row['payment_method']) }})</span>
                        @endif
                    </td>
                    <td class="py-3 px-2 text-right text-rose-600 font-medium">
                        {{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '-' }}
                    </td>
                    <td class="py-3 px-2 text-right text-emerald-600 font-medium">
                        {{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="py-8 text-center text-zinc-400 italic">No transactions found for this account.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-16 pt-8 border-t border-zinc-200 text-center text-xs text-zinc-400">
        <p>This is a computer-generated document and requires no physical signature.</p>
    </div>
</div>

</div>
