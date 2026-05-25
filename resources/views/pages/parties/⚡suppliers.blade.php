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
    }

    public function savePayment(): void
    {
        $this->validate([
            'payAmount' => 'required|numeric|min:0.01',
            'payMethod' => 'required|string',
            'payReference' => 'nullable|string',
            'payNotes' => 'nullable|string',
        ]);

        $supplier = Supplier::query()->findOrFail($this->payingSupplierId);

        if ((float) $this->payAmount > $supplier->due_balance) {
            Flux::toast(variant: 'danger', text: __('Payment amount exceeds total outstanding due.'));
            return;
        }

        // 1. Log polymorphic payment transaction
        $supplier->payments()->create([
            'amount' => (float) $this->payAmount,
            'payment_method' => $this->payMethod,
            'date' => date('Y-m-d'),
            'reference' => $this->payReference,
            'notes' => $this->payNotes ?: 'Dues paid to Supplier Account Ledger.',
        ]);

        // 2. Adjust supplier outstanding due balance
        $supplier->decrement('due_balance', (float) $this->payAmount);

        ActivityLogger::log('supplier_payment', "Disbursed payment of Rs " . (float) $this->payAmount . " to Supplier: {$supplier->name}.");
        Flux::toast(variant: 'success', text: __('Outward payment recorded successfully.'));

        $this->resetPaymentForm();
    }

    public function resetPaymentForm(): void
    {
        $this->reset('payingSupplierId', 'payAmount', 'payMethod', 'payReference', 'payNotes');
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
                'raw_date' => $pm->created_at,
            ]);

        // Merge and sort chronologically
        return $purchases->concat($payments)->sortBy('raw_date')->values()->all();
    }
}; ?>

<div class="flex flex-col gap-6" x-data="{ ledgerOpen: @entangle('selectedSupplierId'), payOpen: @entangle('payingSupplierId') }">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Suppliers Directory') }}</h1>
            <p class="text-sm text-zinc-500">{{ __('Register wholesale vendors, log outward accounts payable remittances, and review vendor statements.') }}</p>
        </div>
    </div>

    <!-- Main Section Grid -->
    <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
        <!-- 1. Supplier Form Card -->
        <div class="app-card p-5 h-fit">
            <div class="flex flex-col gap-1 border-b border-zinc-100 pb-4">
                <h3 class="font-display text-base font-semibold text-zinc-900">
                    {{ $supplierId ? __('Edit Supplier Profile') : __('Add Supplier Profile') }}
                </h3>
                <p class="text-xs text-zinc-500">{{ __('Save wholesale vendor details for inventory purchases and balance audits.') }}</p>
            </div>

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
                        <flux:button type="button" wire:click="resetForm" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                </div>
            </form>
        </div>

        <!-- 2. Interactive Suppliers List Grid -->
        <div class="flex flex-col gap-4">
            <div class="app-card p-4 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input
                        wire:model.live.debounce.300ms="search"
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
                                    <h5 class="text-sm font-semibold text-zinc-900 mt-0.5">{{ $row['ref'] }}</h5>
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
                </flux:select>

                <flux:input wire:model="payReference" :label="__('Transaction Reference (e.g. Bank Deposit Slip / TxID)')" />
                <flux:textarea wire:model="payNotes" :label="__('Payment Ledger Notes')" rows="2" placeholder="Supplier dues payoff." />

                <flux:button type="submit" variant="primary" class="w-full mt-2">
                    {{ __('Record Outward Remittance') }}
                </flux:button>
            </form>
        </div>
    </div>
</div>
