<?php

use App\Models\Supplier;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Manage Suppliers')] class extends Component
{
    use WithPagination;

    public string $search = '';

    // Supplier Form state
    public ?int $supplierId = null;
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $company_name = '';
    public string $address = '';
    public $opening_balance = 0.00;

    // Fast-action payment collection state
    public ?int $payingSupplierId = null;
    public $payAmount = 0.00;
    public string $payMethod = 'cash';
    public string $payReference = '';
    public string $payNotes = '';
    public string $payChequeType = 'party';
    public string $payChequeNo = '';
    public string $payChequeBank = '';
    public string $payChequeDate = '';
    public string $payPartyChequeSearch = '';
    public ?int $payPartyChequePaymentId = null;
    public string $payChequeNo = '';
    public string $payChequeBank = '';
    public string $payChequeDate = '';

    // Detail ledger statement state
    public ?int $selectedSupplierId = null;

    protected $queryString = ['search'];

    public function mount(): void
    {
        if ($supplierId = request('supplier_id')) {
            $this->selectedSupplierId = (int) $supplierId;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function saveSupplier(): void
    {
        $rules = [
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'company_name' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'required|numeric|min:0',
        ];

        $this->validate($rules);

        if ($this->supplierId) {
            $supplier = Supplier::query()->findOrFail($this->supplierId);
            $supplier->update([
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'company_name' => $this->company_name,
                'address' => $this->address,
            ]);

            ActivityLogger::log('supplier_update', "Updated supplier details for {$this->name}.");
            Flux::toast(variant: 'success', text: __('Supplier details updated.'));
        } else {
            $supplier = Supplier::query()->create([
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'company_name' => $this->company_name,
                'address' => $this->address,
                'opening_balance' => (float) $this->opening_balance,
                'due_balance' => (float) $this->opening_balance, // opening balance is initial due
            ]);

            ActivityLogger::log('supplier_create', "Registered wholesale supplier: {$this->name}.");
            Flux::toast(variant: 'success', text: __('New supplier registered.'));
        }

        $this->resetForm();
    }

    public function editSupplier(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);
        $this->supplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->phone = $supplier->phone ?? '';
        $this->email = $supplier->email ?? '';
        $this->company_name = $supplier->company_name ?? '';
        $this->address = $supplier->address ?? '';
        $this->opening_balance = (float) $supplier->opening_balance;
    }

    public function deleteSupplier(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);
        if ($supplier->purchases()->count() > 0) {
            Flux::toast(variant: 'danger', text: __('Cannot remove supplier: associated purchase restocks exist.'));
            return;
        }

        ActivityLogger::log('supplier_delete', "Deleted supplier account: {$supplier->name}.");
        $supplier->delete();
        Flux::toast(variant: 'success', text: __('Supplier deleted.'));
    }

    public function viewLedger(int $id): void
    {
        $this->selectedSupplierId = $id;
    }

    public function closeLedger(): void
    {
        $this->selectedSupplierId = null;
    }

    public function initiatePayment(int $id): void
    {
        $supplier = Supplier::query()->findOrFail($id);
        $this->payingSupplierId = $supplier->id;
        $this->payAmount = (float) $supplier->due_balance;
        $this->payChequeType = 'party';
        $this->payChequeDate = today()->toDateString();
    }

    public function updatedPayMethod(): void
    {
        if ($this->payMethod !== 'cheque') {
            $this->payChequeType = 'party';
            $this->payChequeNo = '';
            $this->payChequeBank = '';
            $this->payChequeDate = '';
            $this->payPartyChequeSearch = '';
            $this->payPartyChequePaymentId = null;

            return;
        }

        $this->payChequeDate = today()->toDateString();
    }

    public function savePayment(): void
    {
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payMethod' => 'required|in:cash,card,bank_transfer,qr,cheque',
            'payReference' => 'nullable|string|max:120',
            'payNotes' => 'nullable|string|max:500',
            'payChequeType' => 'required_if:payMethod,cheque|nullable|in:own,party',
            'payChequeNo' => 'required_if:payChequeType,own|nullable|string|max:100',
            'payChequeBank' => 'nullable|string|max:100',
            'payChequeDate' => 'required_if:payChequeType,own|nullable|date',
            'payPartyChequePaymentId' => 'nullable|exists:payments,id',
        ]);

        $supplier = Supplier::query()->findOrFail($this->payingSupplierId);

        if ((float) $this->payAmount > $supplier->due_balance) {
            Flux::toast(variant: 'danger', text: __('Payment amount exceeds total outstanding due.'));
            return;
        }

        $partyCheque = $this->selectedPayPartyCheque;

        if ($this->payMethod === 'cheque' && $this->payChequeType === 'party') {
            if (! $partyCheque) {
                $this->addError('payPartyChequePaymentId', __('Please select a customer cheque.'));

                return;
            }

            if ((float) $this->payAmount > (float) $partyCheque->amount) {
                $this->addError('payAmount', __('Party cheque amount cannot exceed the selected customer cheque amount.'));

                return;
            }
        }

        // 1. Log polymorphic payment transaction
        $supplier->payments()->create([
            'amount' => (float) $this->payAmount,
            'payment_method' => $this->payMethod,
            'date' => date('Y-m-d'),
            'reference' => $this->payMethod === 'cheque'
                ? ($this->payChequeType === 'party' ? ($partyCheque?->cheque_no ?: $partyCheque?->reference) : $this->payChequeNo)
                : $this->payReference,
            'cheque_bank' => $this->payMethod === 'cheque'
                ? ($this->payChequeType === 'party' ? $partyCheque?->cheque_bank : $this->payChequeBank)
                : null,
            'cheque_no' => $this->payMethod === 'cheque'
                ? ($this->payChequeType === 'party' ? $partyCheque?->cheque_no : $this->payChequeNo)
                : null,
            'cheque_date' => $this->payMethod === 'cheque'
                ? ($this->payChequeType === 'party' ? $partyCheque?->cheque_date : $this->payChequeDate)
                : null,
            'cheque_status' => $this->payMethod === 'cheque' ? 'pending' : null,
            'cheque_type' => $this->payMethod === 'cheque' ? $this->payChequeType : null,
            'source_payment_id' => $this->payMethod === 'cheque' && $this->payChequeType === 'party' ? $partyCheque?->id : null,
            'party_customer_id' => $this->payMethod === 'cheque' && $this->payChequeType === 'party'
                ? $partyCheque?->paymentable?->customer_id
                : null,
            'notes' => $this->payMethod === 'cheque'
                ? ($this->payChequeType === 'party'
                    ? 'Supplier payoff with party cheque on hold until cleared.'
                    : 'Supplier cheque payoff on hold until cleared.')
                : ($this->payNotes ?: 'Dues paid to Supplier Account Ledger.'),
        ]);

        // 2. Adjust supplier outstanding due balance
        $supplier->decrement('due_balance', (float) $this->payAmount);

        ActivityLogger::log('supplier_payment', "Disbursed payment of Rs " . (float) $this->payAmount . " to Supplier: {$supplier->name}.");
        Flux::toast(variant: 'success', text: __('Outward payment recorded successfully.'));

        $this->resetPaymentForm();
    }

    public function resetPaymentForm(): void
    {
        $this->reset(
            'payingSupplierId',
            'payAmount',
            'payMethod',
            'payReference',
            'payNotes',
            'payChequeType',
            'payChequeNo',
            'payChequeBank',
            'payChequeDate',
            'payPartyChequeSearch',
            'payPartyChequePaymentId'
        );
    }

    public function resetForm(): void
    {
        $this->reset('supplierId', 'name', 'phone', 'email', 'company_name', 'address', 'opening_balance');
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()
            ->where(function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('company_name', 'like', '%' . $this->search . '%');
            })
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    #[Computed]
    public function selectedSupplier()
    {
        return $this->selectedSupplierId ? Supplier::query()->findOrFail($this->selectedSupplierId) : null;
    }

    #[Computed]
    public function selectedSupplierLedger()
    {
        if (! $this->selectedSupplierId) return [];

        $purchases = Purchase::query()
            ->where('supplier_id', $this->selectedSupplierId)
            ->get()
            ->map(fn($pur) => [
                'type' => 'invoice',
                'id' => $pur->id,
                'date' => $pur->date,
                'ref' => $pur->invoice_no,
                'description' => "Restock Invoice - Status: " . strtoupper($pur->payment_status),
                'credit' => $pur->grand_total,
                'debit' => 0,
                'raw_date' => $pur->created_at,
            ]);

        $payments = Payment::query()
            ->where('paymentable_type', Supplier::class)
            ->where('paymentable_id', $this->selectedSupplierId)
            ->get()
            ->map(fn($pm) => [
                'type' => 'payment',
                'id' => $pm->id,
                'date' => $pm->date,
                'ref' => $pm->reference ?: 'PAY-' . $pm->id,
                'description' => "Payoff Remitted (" . strtoupper($pm->payment_method) . ")",
                'credit' => 0,
                'debit' => $pm->amount,
                'cheque_status' => $pm->payment_method === 'cheque' ? $pm->cheque_status : null,
                'raw_date' => $pm->created_at,
            ]);

        // Merge and sort chronologically
        return $purchases->concat($payments)->sortBy('raw_date')->values()->all();
    }

    #[Computed]
    public function payPartyCheques()
    {
        if (blank($this->payPartyChequeSearch)) {
            return [];
        }

        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->whereDoesntHave('issuedPayments', fn ($query) => $query->where('cheque_status', 'pending'))
            ->where(function ($query): void {
                $query->where('cheque_no', 'like', '%'.$this->payPartyChequeSearch.'%')
                    ->orWhere('reference', 'like', '%'.$this->payPartyChequeSearch.'%');
            })
            ->with('paymentable.customer')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function selectedPayPartyCheque()
    {
        if (! $this->payPartyChequePaymentId) {
            return null;
        }

        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->with('paymentable.customer')
            ->find($this->payPartyChequePaymentId);
    }

    public function selectPayPartyCheque(int $paymentId): void
    {
        $payment = Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', \App\Models\Sale::class)
            ->with('paymentable.customer')
            ->findOrFail($paymentId);

        $this->payPartyChequePaymentId = $payment->id;
        $this->payPartyChequeSearch = $payment->cheque_no ?: (string) $payment->reference;
        $this->payChequeType = 'party';
        $this->payAmount = min((float) $payment->amount, (float) $this->payAmount);
    }
}; ?>

<div
    class="flex flex-col gap-6"
    x-data="{
        ledgerOpen: @entangle('selectedSupplierId'),
        payOpen: @entangle('payingSupplierId'),
        supplierFormOpen: window.innerWidth >= 1024,
        editingSupplierId: @entangle('supplierId'),
    }"
    x-effect="if (editingSupplierId) supplierFormOpen = true"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Suppliers Directory') }}</h1>
            <p class="text-sm text-zinc-500">{{ __('Register wholesale vendors, log outward accounts payable remittances, and review vendor statements.') }}</p>
        </div>
    </div>

    <!-- Main Section Grid -->
    <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
        <!-- 1. Supplier Form Card -->
        <div class="app-card h-fit p-5">
            <div
                class="flex cursor-pointer items-center justify-between gap-4 border-b border-zinc-100 pb-4"
                @click="supplierFormOpen = !supplierFormOpen"
            >
                <div class="flex flex-col gap-1">
                    <h3 class="font-display text-base font-semibold text-zinc-900">
                        {{ $supplierId ? __('Edit Supplier Profile') : __('Add Supplier Profile') }}
                    </h3>
                    <p class="text-xs text-zinc-500">{{ __('Save wholesale vendor details for inventory purchases and balance audits.') }}</p>
                </div>
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-50 transition hover:bg-zinc-100">
                    <flux:icon.chevron-down class="size-4 text-zinc-500 transition-transform duration-200" x-bind:class="supplierFormOpen ? 'rotate-180' : ''" />
                </div>
            </div>

            <div x-show="supplierFormOpen" x-collapse>
                <form wire:submit="saveSupplier" class="mt-4 flex flex-col gap-4">
                    <flux:input wire:model="name" :label="__('Vendor Contact Person')" required />
                    <flux:input wire:model="company_name" :label="__('Company / Distributor Name')" placeholder="e.g. Imran Wholesale Distributors" />
                    <flux:input wire:model="phone" :label="__('Phone Number')" placeholder="e.g. 0777999888" />
                    <flux:input wire:model="email" :label="__('Email Address')" type="email" placeholder="e.g. sales@wholesale.com" />
                    <flux:textarea wire:model="address" :label="__('Vendor Address')" rows="2" />

                    @if (! $supplierId)
                        <flux:input wire:model="opening_balance" :label="__('Opening Dues (Initial Accounts Payable)')" type="number" step="0.01" />
                    @endif

                    <div class="mt-4 flex gap-2">
                        <flux:button type="submit" variant="primary" class="flex-1">
                            {{ $supplierId ? __('Update Supplier') : __('Register Supplier') }}
                        </flux:button>
                        @if ($supplierId)
                            <flux:button type="button" wire:click="resetForm" @click="if (window.innerWidth < 1024) supplierFormOpen = false" variant="ghost">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <!-- 2. Interactive Suppliers List Grid -->
        <div class="flex flex-col gap-4">
            <div class="app-card p-4 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input
                        wire:model.live.debounce.500ms="search"
                        type="text"
                        placeholder="Search suppliers by name or company name..."
                        class="w-full bg-transparent text-sm text-zinc-950 focus:outline-none"
                    />
                </div>
            </div>

            <div class="grid gap-3">
                @foreach ($this->suppliers as $s)
                    <div class="app-card p-4 flex flex-col gap-3 justify-between sm:flex-row sm:items-center" wire:key="supplier-item-{{ $s->id }}">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 font-semibold">
                                {{ Str::substr($s->name, 0, 2) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-zinc-900">{{ $s->name }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    @if ($s->company_name)
                                        <flux:badge size="sm" color="orange" class="font-semibold">
                                            {{ $s->company_name }}
                                        </flux:badge>
                                    @endif
                                    @if ($s->phone)
                                        <flux:badge size="sm" color="zinc">
                                            <flux:icon.phone class="size-3 mr-1 text-zinc-500" />
                                            {{ $s->phone }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Actions / Account Info -->
                        <div class="flex items-center justify-between border-t border-zinc-100 pt-3 sm:border-t-0 sm:pt-0 sm:gap-4">
                            <div class="flex flex-col sm:items-end">
                                <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">{{ __('Payables Due') }}</span>
                                <span class="text-sm font-bold {{ $s->due_balance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                    Rs {{ number_format($s->due_balance, 2) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1">
                                <flux:button variant="ghost" size="sm" wire:click="viewLedger({{ $s->id }})">
                                    Ledger
                                </flux:button>
                                
                                @if ($s->due_balance > 0)
                                    <flux:button variant="ghost" size="sm" class="text-emerald-600 hover:text-emerald-700" wire:click="initiatePayment({{ $s->id }})">
                                        Payoff
                                    </flux:button>
                                @endif

                                <flux:button variant="ghost" size="sm" wire:click="editSupplier({{ $s->id }})">
                                    Edit
                                </flux:button>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-rose-500 px-2 py-1 hover:underline"
                                    x-on:click.prevent="if(confirm('Remove this supplier record?')) { $wire.deleteSupplier({{ $s->id }}) }"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2">
                {{ $this->suppliers->links() }}
            </div>
        </div>
    </div>

    <!-- 3. SUPPLIER LEDGER STATEMENT DRAWER / MODAL -->
    <div
        x-cloak
        x-show="ledgerOpen"
        class="fixed inset-0 z-50 flex items-center justify-end bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="h-full w-full max-w-xl bg-white rounded-3xl shadow-2xl flex flex-col overflow-hidden"
            @click.away="ledgerOpen = null; $wire.closeLedger()"
            x-transition:enter="ease-out duration-300 transform"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="ease-in duration-200 transform"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            <!-- Drawer Header -->
            <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                <div>
                    <h3 class="font-display font-bold text-zinc-950">
                        {{ $this->selectedSupplier?->name }}
                    </h3>
                    <p class="text-xs text-zinc-500 mt-1">{{ __('Wholesale Purchase & Payoff Ledger Statement') }}</p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="closeLedger">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <!-- Ledger Rows Scroll List -->
            <div class="flex-1 overflow-y-auto p-5 scrollbar-none flex flex-col gap-4">
                <!-- Supplier Quick Info Card -->
                <div class="grid grid-cols-2 gap-4 rounded-2xl bg-zinc-50 border border-zinc-100 p-4">
                    <div>
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider block">{{ __('Outstanding Payables') }}</span>
                        <span class="text-lg font-bold text-zinc-900">
                            Rs {{ number_format($this->selectedSupplier?->due_balance ?? 0, 2) }}
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider block">{{ __('Opening Balance') }}</span>
                        <span class="text-lg font-semibold text-zinc-600">
                            Rs {{ number_format($this->selectedSupplier?->opening_balance ?? 0, 2) }}
                        </span>
                    </div>
                </div>

                <!-- Ledger Timeline -->
                <div class="flex flex-col gap-3">
                    <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Account Timeline') }}</h4>

                    @forelse ($this->selectedSupplierLedger as $row)
                        <div class="flex flex-col gap-1 rounded-2xl border border-zinc-100 p-4 hover:bg-zinc-50/50 transition">
                            <div class="flex items-start justify-between">
                                <div>
                                    <span class="text-xs text-zinc-400">{{ $row['date']->format('Y-m-d') }}</span>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                        <h5 class="text-sm font-semibold text-zinc-900">{{ $row['ref'] }}</h5>
                                        @if (! empty($row['cheque_status']))
                                            <span @class([
                                                'text-[9px] font-black uppercase tracking-wider rounded-full px-2 py-0.5',
                                                'bg-amber-100/60 text-amber-700' => $row['cheque_status'] === 'pending',
                                                'bg-emerald-100/70 text-emerald-700' => $row['cheque_status'] === 'passed',
                                                'bg-rose-100/70 text-rose-700' => $row['cheque_status'] === 'returned',
                                                'bg-zinc-100 text-zinc-600' => ! in_array($row['cheque_status'], ['pending', 'passed', 'returned'], true),
                                            ])>
                                                {{ str($row['cheque_status'])->replace('_', ' ')->headline() }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right">
                                    @if ($row['credit'] > 0)
                                        <span class="text-sm font-bold text-rose-600">+ Rs {{ number_format($row['credit'], 2) }}</span>
                                    @else
                                        <span class="text-sm font-bold text-emerald-600">- Rs {{ number_format($row['debit'], 2) }}</span>
                                    @endif
                                </div>
                            </div>
                            <p class="text-xs text-zinc-500">{{ $row['description'] }}</p>
                        </div>
                    @empty
                        <div class="py-10 text-center text-xs text-zinc-400">
                            {{ __('No purchase restocks or outward remittances logged.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- 4. REMIT SUPPLIER DUE PAYOFF DRAWER -->
    <div
        x-cloak
        x-show="payOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 transition-opacity backdrop-blur-sm"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div
            class="w-full max-w-md bg-white rounded-3xl shadow-2xl p-5"
            @click.away="payOpen = null; $wire.resetPaymentForm()"
            x-transition:enter="ease-out duration-300 transform scale-95"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="ease-in duration-200 transform scale-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="flex items-center justify-between border-b border-zinc-100 pb-3">
                <h3 class="font-display text-base font-bold text-zinc-950">{{ __('Remit Supplier Payoff') }}</h3>
                <flux:button variant="ghost" size="sm" wire:click="resetPaymentForm">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <form wire:submit="savePayment" class="mt-4 flex flex-col gap-4">
                <div class="rounded-2xl bg-zinc-50 border border-zinc-100 p-4">
                    <p class="text-xs text-zinc-500 uppercase tracking-wider font-semibold">{{ __('Total Pending Accounts Payable') }}</p>
                    <p class="text-lg font-bold text-rose-600 mt-0.5">
                        Rs {{ number_format(Supplier::query()->find($this->payingSupplierId)?->due_balance ?? 0, 2) }}
                    </p>
                </div>

                <flux:input wire:model="payAmount" :label="__('Remittance Amount Remitted (Rs)')" type="number" step="0.01" required />
                
                <flux:select wire:model="payMethod" :label="__('Payment Method')">
                    <option value="cash">Cash Account</option>
                    <option value="card">Credit / Debit Card</option>
                    <option value="bank_transfer">Direct Bank Deposit</option>
                    <option value="qr">LankaQR / QR Scan</option>
                    <option value="cheque">Cheque Hold</option>
                </flux:select>

                @if ($payMethod === 'cheque')
                    <flux:select wire:model.live="payChequeType" :label="__('Cheque Type')">
                        <option value="own">{{ __('Own Cheque') }}</option>
                        <option value="party">{{ __('Party Cheque') }}</option>
                    </flux:select>

                    @if ($payChequeType === 'own')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="payChequeNo" :label="__('Cheque No')" placeholder="Cheque number" required />
                            <flux:input wire:model="payChequeBank" :label="__('Bank')" placeholder="Bank name" />
                        </div>
                        <flux:input wire:model="payChequeDate" :label="__('Cheque Date')" type="date" required />
                        <p class="rounded-2xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                            {{ __('Own cheques are shown on the dashboard from 3 days before the cheque date and become cash out when marked passed.') }}
                        </p>
                    @else
                        <div class="relative" x-data="{ open: false }" @click.away="open = false">
                            <flux:input wire:model.live.debounce.150ms="payPartyChequeSearch" :label="__('Customer Cheque No')" placeholder="Search pending customer cheque..." @focus="open = true" required />

                            @if (count($this->payPartyCheques) > 0)
                                <div x-cloak x-show="open" class="absolute z-40 mt-2 max-h-60 w-full overflow-y-auto rounded-2xl border border-zinc-100 bg-white p-2 shadow-xl">
                                    @foreach ($this->payPartyCheques as $partyCheque)
                                        @php($partySale = $partyCheque->paymentable)
                                        <button type="button" wire:click="selectPayPartyCheque({{ $partyCheque->id }})" @click="open = false" class="w-full rounded-xl p-3 text-left transition hover:bg-zinc-50">
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

                        @if ($this->selectedPayPartyCheque)
                            @php($selectedPartySale = $this->selectedPayPartyCheque->paymentable)
                            <div class="rounded-2xl border border-violet-100 bg-violet-50 p-3 text-xs text-violet-900">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-black">{{ $this->selectedPayPartyCheque->cheque_no ?: $this->selectedPayPartyCheque->reference }}</span>
                                    <span class="font-black">Rs {{ number_format($this->selectedPayPartyCheque->amount, 2) }}</span>
                                </div>
                                <p class="mt-1 font-semibold">
                                    {{ $selectedPartySale?->customer?->name ?? __('Unknown Customer') }} · {{ __('Due') }} {{ $this->selectedPayPartyCheque->cheque_date?->format('Y-m-d') }}
                                </p>
                            </div>
                        @endif

                        <p class="rounded-2xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                            {{ __('Party cheques are shown separately on the dashboard from 2 days before the cheque date with supplier details and pass/return actions.') }}
                        </p>
                    @endif
                @else
                    <flux:input wire:model="payReference" :label="__('Transaction Reference (e.g. Bank Deposit Slip / TxID)')" />
                @endif

                <flux:textarea wire:model="payNotes" :label="__('Payment Ledger Notes')" rows="2" placeholder="Supplier dues payoff." />

                <flux:button type="submit" variant="primary" class="w-full mt-2">
                    {{ __('Record Outward Remittance') }}
                </flux:button>
            </form>
        </div>
    </div>
</div>
