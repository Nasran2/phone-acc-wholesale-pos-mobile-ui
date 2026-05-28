<?php

use App\Models\Purchase;
use App\Models\Supplier;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Wholesale Purchase Logs')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $selectedSupplier = null;
    public string $paymentStatus = 'ALL';

    // Detail show state
    public ?int $viewingPurchaseId = null;

    protected $queryString = ['search', 'selectedSupplier', 'paymentStatus'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function viewInvoice(int $id): void
    {
        $this->viewingPurchaseId = $id;
    }

    public function closeInvoice(): void
    {
        $this->viewingPurchaseId = null;
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()->orderBy('name')->get();
    }

    #[Computed]
    public function filteredPurchasesQuery()
    {
        $query = Purchase::query()->with(['supplier', 'items.product']);

        if ($this->search) {
            $query->where('invoice_no', 'like', '%' . $this->search . '%');
        }

        if ($this->selectedSupplier) {
            $query->where('supplier_id', $this->selectedSupplier);
        }

        if ($this->paymentStatus !== 'ALL') {
            $query->where('payment_status', $this->paymentStatus);
        }

        return $query;
    }

    #[Computed]
    public function purchases()
    {
        return $this->filteredPurchasesQuery()
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    /**
     * @return array{count:int,total:float,due:float}
     */
    #[Computed]
    public function purchaseSummary(): array
    {
        $query = $this->filteredPurchasesQuery();

        return [
            'count' => (clone $query)->count(),
            'total' => (float) (clone $query)->sum('grand_total'),
            'due' => (float) (clone $query)->sum('due_amount'),
        ];
    }

    #[Computed]
    public function selectedPurchase()
    {
        if (! $this->viewingPurchaseId) return null;

        return Purchase::query()
            ->with(['supplier', 'items.product', 'payments'])
            ->findOrFail($this->viewingPurchaseId);
    }
}; ?>

<div class="flex flex-col gap-6" x-data="{ drawerOpen: @entangle('viewingPurchaseId') }">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Purchase Invoices') }}</h1>
            <p class="text-sm text-zinc-500">{{ __('Auditing incoming shop accessories restocks, supplier accounts payable balances, and receipt histories.') }}</p>
        </div>
        <flux:button href="{{ route('purchases.create') }}" wire:navigate variant="primary" class="w-full sm:w-auto">
            <flux:icon.plus class="size-4 mr-1" />
            {{ __('New Purchase Order') }}
        </flux:button>
    </div>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="app-card p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
                    <flux:icon.clipboard-document-list class="size-5" />
                </div>
                <flux:badge size="sm" color="zinc">{{ __('Invoices') }}</flux:badge>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Filtered Purchases') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-zinc-950">{{ number_format($this->purchaseSummary['count']) }}</p>
        </div>
        <div class="app-card p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <flux:icon.banknotes class="size-5" />
                </div>
                <flux:badge size="sm" color="emerald">{{ __('Total') }}</flux:badge>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Grand Total Value') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-zinc-950">Rs {{ number_format($this->purchaseSummary['total'], 2) }}</p>
        </div>
        <div class="app-card p-4">
            <div class="flex items-center justify-between">
                <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
                    <flux:icon.exclamation-triangle class="size-5" />
                </div>
                <flux:badge size="sm" color="rose">{{ __('Due') }}</flux:badge>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Outstanding Payables') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-zinc-950">Rs {{ number_format($this->purchaseSummary['due'], 2) }}</p>
        </div>
    </section>

    <!-- Multi-criteria Filter Bar -->
    <div class="app-card p-4 grid gap-4 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by Invoice No..." />
        
        <flux:select wire:model.live="selectedSupplier" placeholder="All Wholesale Suppliers">
            @foreach ($this->suppliers as $sup)
                <option value="{{ $sup->id }}">{{ $sup->name }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="paymentStatus">
            <option value="ALL">All Payment Statuses</option>
            <option value="paid">Fully Paid Invoices</option>
            <option value="partial">Partially Paid</option>
            <option value="due">Pending Outstanding Dues</option>
        </flux:select>
    </div>

    <!-- Purchases Listings Grid -->
    <div class="grid gap-3">
        @forelse ($this->purchases as $p)
            <div
                class="app-card p-4 flex flex-col gap-4 justify-between sm:flex-row sm:items-center hover:bg-zinc-50/50 transition cursor-pointer"
                wire:click="viewInvoice({{ $p->id }})"
                wire:key="purchase-card-{{ $p->id }}"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-600">
                        <flux:icon.arrow-down-left class="size-5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-zinc-900">{{ $p->invoice_no }}</span>
                            @if ($p->payment_status === 'paid')
                                <flux:badge size="sm" color="emerald">Paid</flux:badge>
                            @elseif ($p->payment_status === 'partial')
                                <flux:badge size="sm" color="orange">Partial</flux:badge>
                            @elseif ($p->payment_status === 'cheque_pending')
                                <flux:badge size="sm" color="amber">Cheque Hold</flux:badge>
                            @else
                                <flux:badge size="sm" color="rose">Due</flux:badge>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                            <span>{{ $p->date->format('Y-m-d') }}</span>
                            <span class="inline-block h-1 w-1 rounded-full bg-zinc-300"></span>
                            <a href="{{ route('parties.suppliers', ['supplier_id' => $p->supplier_id]) }}" wire:navigate @click.stop class="font-semibold text-zinc-700 hover:text-violet-600 hover:underline">
                                {{ $p->supplier?->name }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-zinc-100 pt-3 sm:border-t-0 sm:pt-0 sm:gap-6">
                    <div class="flex flex-col sm:items-end">
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">Grand Total</span>
                        <span class="text-sm font-bold text-zinc-950">Rs {{ number_format($p->grand_total, 2) }}</span>
                    </div>

                    <div class="flex flex-col sm:items-end">
                        <span class="text-[10px] text-zinc-400 uppercase font-semibold tracking-wider">Remaining Due</span>
                        <span class="text-sm font-semibold {{ $p->due_amount > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            Rs {{ number_format($p->due_amount, 2) }}
                        </span>
                    </div>
                </div>
            </div>
        @empty
            <div class="py-12 text-center text-xs text-zinc-400 bg-white rounded-3xl border border-zinc-100 shadow-sm">
                {{ __('No incoming shipment records found.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-2">
        {{ $this->purchases->links() }}
    </div>

    <!-- Detailed Invoice Slide-open Drawer -->
    <div
        x-cloak
        x-show="drawerOpen"
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
            @click.away="drawerOpen = null; $wire.closeInvoice()"
            x-transition:enter="ease-out duration-300 transform"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="ease-in duration-200 transform"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            @if ($this->selectedPurchase)
                <!-- Drawer Header -->
                <div class="flex items-center justify-between border-b border-zinc-100 p-5 bg-zinc-50/50">
                    <div>
                        <h3 class="font-display font-bold text-zinc-950">
                            {{ $this->selectedPurchase->invoice_no }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-1">{{ __('Wholesale Purchase Details Audit View') }}</p>
                    </div>
                    <flux:button variant="ghost" size="sm" wire:click="closeInvoice">
                        <flux:icon.x-mark class="size-4" />
                    </flux:button>
                </div>

                <!-- Invoice Content Scroll View -->
                <div class="flex-1 overflow-y-auto p-5 scrollbar-none flex flex-col gap-5">
                    <!-- Supplier quick contact -->
                    <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4 flex justify-between items-center text-xs">
                        <div>
                            <span class="text-[10px] text-zinc-400 font-semibold uppercase tracking-wider block">Wholesale Supplier</span>
                            <a href="{{ route('parties.suppliers', ['supplier_id' => $this->selectedPurchase->supplier_id]) }}" wire:navigate class="font-bold text-zinc-900 text-sm mt-0.5 block hover:text-violet-600 hover:underline">
                                {{ $this->selectedPurchase->supplier?->name }}
                            </a>
                            <span class="text-zinc-500 mt-0.5 block">{{ $this->selectedPurchase->supplier?->company_name }}</span>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] text-zinc-400 font-semibold uppercase tracking-wider block">Restock Date</span>
                            <span class="font-semibold text-zinc-800 text-sm mt-0.5 block">{{ $this->selectedPurchase->date->format('Y-m-d') }}</span>
                        </div>
                    </div>

                    <!-- Items purchased listing -->
                    <div class="flex flex-col gap-2">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1">{{ __('Wholesale Products Restocked') }}</h4>
                        
                        @foreach ($this->selectedPurchase->items as $item)
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-2.5 text-xs">
                                <div>
                                    @if ($item->product)
                                        <a href="{{ route('products.show', $item->product_id) }}" wire:navigate class="font-bold text-zinc-900 hover:text-violet-600 hover:underline">
                                            {{ $item->product->name }}
                                        </a>
                                    @else
                                        <span class="font-bold text-zinc-500 italic">{{ __('Deleted Product') }}</span>
                                    @endif
                                    <span class="text-[10px] text-zinc-400 uppercase font-mono mt-0.5 block">
                                        Qty: <span class="font-semibold text-zinc-800">{{ $item->quantity }}</span> | Cost: Rs {{ number_format($item->cost_price, 2) }}
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
                            <span class="font-semibold text-zinc-900">Rs {{ number_format($this->selectedPurchase->total_amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Invoice Discount</span>
                            <span class="font-semibold text-rose-600">- Rs {{ number_format($this->selectedPurchase->discount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Order Tax</span>
                            <span class="font-semibold text-zinc-900">+ Rs {{ number_format($this->selectedPurchase->tax, 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-zinc-100 pt-2 text-sm">
                            <span class="font-bold text-zinc-950">Grand Total</span>
                            <span class="font-bold text-orange-600">Rs {{ number_format($this->selectedPurchase->grand_total, 2) }}</span>
                        </div>
                    </div>

                    <!-- Outward payments list -->
                    <div class="border-t border-zinc-100 pt-4 flex flex-col gap-3">
                        <h4 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ __('Outward Payments Remitted') }}</h4>
                        
                        @forelse ($this->selectedPurchase->payments as $pm)
                            <div class="flex items-center justify-between rounded-xl bg-zinc-50 border border-zinc-100 p-3 text-xs">
                                <div>
                                    <span class="font-bold text-zinc-800 capitalize">{{ $pm->payment_method }} Account</span>
                                    <span class="text-[10px] text-zinc-400 mt-0.5 block">{{ $pm->date->format('Y-m-d') }}</span>
                                </div>
                                <span class="font-bold text-emerald-600">Rs {{ number_format($pm->amount, 2) }}</span>
                            </div>
                        @empty
                            <div class="py-4 text-center text-[10px] text-zinc-400">
                                {{ __('No payments remitted yet. Outstanding due remains.') }}
                            </div>
                        @endforelse
                    </div>

                    <!-- Internal details / notes -->
                    @if ($this->selectedPurchase->notes)
                        <div class="rounded-2xl border border-dashed border-zinc-200 p-4 text-xs text-zinc-600">
                            <span class="font-semibold block mb-1">Invoice Notes:</span>
                            {{ $this->selectedPurchase->notes }}
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
