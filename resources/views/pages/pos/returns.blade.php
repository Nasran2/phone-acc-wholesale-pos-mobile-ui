<?php

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\SaleReturnService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('POS Returns')] class extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $editingReturnId = null;

    public string $historySearch = '';

    public bool $returnDetailOpen = false;

    public ?int $selectedReturnId = null;

    public string $search = '';

    public ?int $selectedSaleId = null;

    public array $returnItems = [];

    public string $returnType = 'exchange';

    public string $returnNotes = '';

    public function mount(): void
    {
        if (! auth()->user()->hasPermission('process_return')) {
            abort(403, 'Unauthorized return access.');
        }
        if (request()->integer('edit_return') > 0) {
            $this->editReturn(request()->integer('edit_return'));
        }
    }

    public function updatedSearch(): void
    {
        $this->cancelReturnEdit();
        $this->resetPage(pageName: 'invoicesPage');
    }

    public function selectSale(int $saleId): void
    {
        abort_unless(auth()->user()->hasPermission('process_return'), 403);
        $this->editingReturnId = null;
        $sale = Sale::query()->with(['items.product', 'returns.items'])->findOrFail($saleId);

        $this->selectedSaleId = $sale->id;
        $this->returnItems = [];

        $this->returnItems = app(SaleReturnService::class)->availableItems($sale);

        $this->returnType = ((float) $sale->due_amount > 0 || (float) ($sale->customer?->due_balance ?? 0) > 0) ? 'adjust_due' : 'exchange';
        foreach ($this->returnItems as &$item) {
            $item['condition'] = $this->returnType === 'exchange' ? 'faulty' : 'restock';
        }
        unset($item);
        $this->resetValidation();
        $this->returnNotes = '';

        if (count($this->returnItems) === 0) {
            Flux::toast(variant: 'danger', text: __('All products on this invoice have already been returned.'));
        }
    }

    public function updateReturnQty(int $productId, int $quantity): void
    {
        if (! isset($this->returnItems[$productId])) {
            return;
        }

        $quantity = min((int) $this->returnItems[$productId]['max'], max(0, $quantity));

        $this->returnItems[$productId]['quantity'] = $quantity;
        $item = &$this->returnItems[$productId];
        $item['subtotal'] = isset($item['original_quantity']) && $quantity === $item['original_quantity']
            ? $item['original_subtotal']
            : app(SaleReturnService::class)->subtotalFor($item, $quantity);
        unset($item);
    }

    public function submitReturn(SaleReturnService $returnService): void
    {
        abort_unless(auth()->user()->hasPermission('process_return'), 403);

        $this->validate([
            'selectedSaleId' => 'required|exists:sales,id',
            'returnItems' => 'required|array',
            'returnType' => 'required|in:cash_refund,adjust_due,exchange',
            'returnNotes' => 'nullable|string|max:1000',
        ]);

        $sale = Sale::query()->with(['customer', 'items.product'])->findOrFail($this->selectedSaleId);

        if ($this->editingReturnId) {
            $return = SaleReturn::query()->where('sale_id', $sale->id)->findOrFail($this->editingReturnId);
            $return = $returnService->update($return, $this->returnItems, $this->returnType, $this->returnNotes);
        } else {
            $return = $returnService->process($sale, $this->returnItems, $this->returnType, $this->returnNotes);
        }

        Flux::toast(variant: 'success', text: $this->editingReturnId ? __('Return updated successfully.') : __('Return completed successfully.'));
        $this->editingReturnId = null;

        $this->selectedSaleId = null;
        $this->returnItems = [];
        $this->resetPage(pageName: 'returnsPage');
        unset($this->completedReturns);
        $this->viewReturnDetail($return->id);
    }

    public function editReturn(int $returnId): void
    {
        abort_unless(auth()->user()->hasPermission('process_return'), 403);
        $this->resetValidation();
        $return = SaleReturn::query()->with(['sale.items.product', 'items', 'faultyItems'])->findOrFail($returnId);
        $service = app(SaleReturnService::class);
        if ($return->return_type === 'bill_credit') {
            $invoice = str($return->notes ?? '')->after('Return credited on checkout ')->beforeLast('.')->toString();
            $checkout = Sale::query()->where('invoice_no', $invoice)->first();
            if (! $checkout) {
                $this->addError('returnItems', __('The linked checkout bill could not be found.'));
                return;
            }
            $this->redirect(route('pos.index', ['sale' => $checkout->id]), navigate: true);
            return;
        }
        $service->ensureEditable($return);
        $sale = $return->sale;
        abort_unless($sale, 404);
        $sale->setRelation('returns', $sale->returns()->with('items')->whereKeyNot($return->id)->get());
        $this->returnItems = $service->availableItems($sale);
        foreach ($return->items as $returnedItem) {
            if (! isset($this->returnItems[$returnedItem->product_id])) {
                continue;
            }
            $item = &$this->returnItems[$returnedItem->product_id];
            $item['quantity'] += $returnedItem->quantity;
            $item['condition'] = $return->faultyItems->contains('product_id', $returnedItem->product_id) ? 'faulty' : 'restock';
            $item['original_quantity'] = $item['quantity'];
            $item['original_subtotal'] = (float) $return->items->where('product_id', $returnedItem->product_id)->sum('subtotal');
            $item['refund_price'] = (float) $returnedItem->refund_price;
            $item['subtotal'] = $item['original_subtotal'];
            unset($item);
        }
        $this->selectedReturnId = $return->id;
        $this->editingReturnId = $return->id;
        $this->selectedSaleId = $sale->id;
        $this->returnType = $return->return_type;
        $this->returnNotes = $return->notes ?? '';
        $this->returnDetailOpen = false;
        $this->dispatch('return-editor-opened');
    }

    public function cancelReturnEdit(): void
    {
        $this->editingReturnId = null;
        $this->selectedSaleId = null;
        $this->returnItems = [];
        $this->returnNotes = '';
        $this->resetValidation();
    }

    public function deleteReturn(int $returnId, SaleReturnService $service): void
    {
        abort_unless(auth()->user()->hasPermission('process_return'), 403);
        $this->resetValidation();
        $service->revert(SaleReturn::query()->findOrFail($returnId));
        if ($this->editingReturnId === $returnId) {
            $this->cancelReturnEdit();
        }
        if ($this->selectedReturnId === $returnId) {
            $this->selectedReturnId = null;
            $this->returnDetailOpen = false;
        }
        unset($this->selectedReturn, $this->selectedSale, $this->completedReturns, $this->sales);
        $this->resetPage(pageName: 'returnsPage');
        Flux::toast(variant: 'success', text: __('Return deleted and stock, refunds, and balances restored.'));
    }

    public function updatedHistorySearch(): void
    {
        $this->resetPage(pageName: 'returnsPage');
    }

    public function viewReturnDetail(int $returnId): void
    {
        abort_unless(auth()->user()->hasPermission('process_return'), 403);
        SaleReturn::query()->findOrFail($returnId);
        $this->selectedReturnId = $returnId;
        $this->returnDetailOpen = true;
    }

    #[Computed]
    public function selectedReturn(): ?SaleReturn
    {
        return $this->selectedReturnId
            ? SaleReturn::query()->with(['sale', 'customer', 'items.product', 'faultyItems'])->findOrFail($this->selectedReturnId)
            : null;
    }

    #[Computed]
    public function completedReturns()
    {
        return SaleReturn::query()
            ->with(['sale', 'customer', 'items', 'faultyItems'])
            ->when(trim($this->historySearch) !== '', function ($query): void {
                $term = '%'.trim($this->historySearch).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('invoice_no', 'like', $term)
                        ->orWhereHas('sale', fn ($query) => $query->where('invoice_no', 'like', $term))
                        ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(10, pageName: 'returnsPage');
    }

    #[Computed]
    public function sales()
    {
        return Sale::query()
            ->with('customer:id,name,phone')
            ->when($this->search, function ($query): void {
                $query->where(function ($query): void {
                    $query->where('invoice_no', 'like', '%'.$this->search.'%')
                        ->orWhereHas('customer', function ($query): void {
                            $query->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('phone', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(8, pageName: 'invoicesPage');
    }

    #[Computed]
    public function selectedSale(): ?Sale
    {
        if (! $this->selectedSaleId) {
            return null;
        }

        $sale = Sale::query()
            ->with(['customer', 'items.product', 'returns.items.product', 'returns.sale', 'returns.faultyItems'])
            ->find($this->selectedSaleId);
        if ($sale && $this->editingReturnId) {
            $return = $sale->returns->firstWhere('id', $this->editingReturnId);
            $sourceAdjustment = $return?->due_allocations === null ? (float) ($return?->adjusted_amount ?? 0)
                : (float) collect($return->due_allocations['sales'] ?? [])->where('sale_id', $sale->id)->sum('amount');
            $sale->due_amount = (float) $sale->due_amount + $sourceAdjustment;
            if ($sale->customer) {
                $sale->customer->due_balance = (float) $sale->customer->due_balance + (float) ($return?->adjusted_amount ?? 0);
            }
        }

        return $sale;
    }

    #[Computed]
    public function returnTotal(): float
    {
        return round((float) collect($this->returnItems)->sum('subtotal'), 2);
    }
};
?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-4 pb-24 sm:gap-6 lg:pb-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __('POS Returns') }}</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Exchange same products, refund paid bills, or reduce customer due balances.') }}</p>
        </div>

        <flux:button type="button" variant="ghost" icon="document-text" href="{{ route('sales.index') }}" wire:navigate class="w-full sm:w-auto">
            {{ __('Sales List') }}
        </flux:button>
    </div>

    <flux:error name="returnItems" />
    <section class="app-card p-3 sm:p-4">
        <h2 class="mb-3 text-lg font-bold">{{ __('Completed Returns') }}</h2>
        <flux:input wire:model.live.debounce.300ms="historySearch" icon="magnifying-glass" placeholder="Search return, original invoice, customer, or phone..." />
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @forelse ($this->completedReturns as $return)
                <article wire:key="completed-return-{{ $return->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap justify-between gap-2">
                        <span class="font-bold">{{ $return->invoice_no }}</span>
                        <span class="text-sm font-bold text-amber-700 dark:text-amber-300">Rs {{ number_format($return->total_amount, 2) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">{{ $return->customer?->name ?? __('Walk-in Customer') }} · {{ $return->sale?->invoice_no ?? '-' }}</p>
                    <p class="mt-2 text-xs text-zinc-500">{{ $return->date?->format('Y-m-d') }} · {{ $return->type_label }}</p>
                    <p class="mt-2 text-xs">{{ $return->return_type === 'bill_credit' ? __('Bill credit') : __('Due reduced') }}: Rs {{ number_format((float) $return->adjusted_amount, 2) }} · {{ __('Cash refunded') }}: Rs {{ number_format((float) $return->refund_amount, 2) }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="viewReturnDetail({{ $return->id }})">{{ __('View return details') }}</flux:button>
                        <x-sale-return-actions :return="$return" :inline="true" />
                    </div>
                </article>
            @empty
                <p class="py-4 text-sm text-zinc-500">{{ __('No completed returns found.') }}</p>
            @endforelse
        </div>
        <div class="mt-3">{{ $this->completedReturns->links() }}</div>
    </section>

    <section class="app-card p-3 sm:p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="text-lg font-bold">{{ $editingReturnId ? __('Edit Return') : __('New Return') }}</h2>
            @if ($editingReturnId)
                <flux:button size="sm" wire:click="cancelReturnEdit">{{ __('Cancel Edit') }}</flux:button>
            @endif
        </div>
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search invoice, customer, or phone..." />

        <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($this->sales as $sale)
                <button
                    type="button"
                    wire:key="return-invoice-{{ $sale->id }}"
                    wire:click="selectSale({{ $sale->id }})"
                    @class([
                        'rounded-xl border p-3 text-left transition active:scale-[0.99]',
                        'border-violet-300 bg-violet-50 dark:border-violet-700 dark:bg-violet-950/30' => $selectedSaleId === $sale->id,
                        'border-zinc-100 bg-white hover:border-violet-200 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-violet-800' => $selectedSaleId !== $sale->id,
                    ])
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-black text-zinc-950 dark:text-white">{{ $sale->invoice_no }}</p>
                            <p class="mt-1 truncate text-xs text-zinc-500">{{ $sale->customer?->name ?? __('Walk-in Customer') }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-2 py-1 text-[10px] font-black uppercase',
                            'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $sale->due_amount > 0,
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $sale->due_amount <= 0,
                        ])>{{ $sale->payment_status }}</span>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-xs">
                        <span class="text-zinc-500">{{ $sale->date->format('Y-m-d') }}</span>
                        <span class="font-black text-zinc-900 dark:text-zinc-100">{{ __('Rs') }} {{ number_format((float) $sale->grand_total, 2) }}</span>
                    </div>
                </button>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-zinc-800">
                    {{ __('No invoices found.') }}
                </div>
            @endforelse
        </div>
    </section>

    <div>{{ $this->sales->links() }}</div>

    @if ($this->selectedSale)
        <section x-data @return-editor-opened.window="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })" class="grid gap-4 lg:grid-cols-[1fr_360px]">
            <div class="flex flex-col gap-3">
                @foreach ($returnItems as $productId => $item)
                    <article class="rounded-xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" wire:key="return-product-{{ $productId }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-black text-zinc-950 dark:text-white">{{ $item['name'] }}</h2>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('SKU') }}: {{ $item['sku'] ?: '-' }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs text-zinc-500">{{ __('Returnable') }}</p>
                                <p class="text-sm font-black text-zinc-950 dark:text-white">{{ $item['max'] }}</p>
                            </div>
                        </div>

                        <div class="mt-3">
                            <flux:select wire:model.live="returnItems.{{ $productId }}.condition" size="sm" :label="__('Returned condition')">
                                <option value="faulty">{{ __('Faulty Product') }}</option>
                                <option value="restock">{{ __('Restock') }}</option>
                            </flux:select>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-950">
                                <button type="button" wire:click="updateReturnQty({{ $productId }}, {{ $item['quantity'] - 1 }})" class="flex size-10 items-center justify-center rounded-lg bg-white text-lg font-black text-zinc-700 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-100">-</button>
                                <span class="min-w-10 text-center text-base font-black text-zinc-950 dark:text-white">{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="updateReturnQty({{ $productId }}, {{ $item['quantity'] + 1 }})" class="flex size-10 items-center justify-center rounded-lg bg-white text-lg font-black text-zinc-700 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-100">+</button>
                            </div>

                            <div class="text-right">
                                <p class="text-xs text-zinc-500">{{ __('Line value') }}</p>
                                <p class="text-base font-black text-zinc-950 dark:text-white">{{ __('Rs') }} {{ number_format((float) $item['subtotal'], 2) }}</p>
                            </div>
                        </div>

                        @if ($returnType === 'exchange')
                            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                                {{ __('Current same-product stock') }}: {{ $item['stock'] }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>

            <aside class="h-fit rounded-xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <p class="text-xs font-bold uppercase text-zinc-400">{{ __('Selected invoice') }}</p>
                    <h2 class="mt-1 text-lg font-black text-zinc-950 dark:text-white">{{ $this->selectedSale->invoice_no }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ $this->selectedSale->customer?->name ?? __('Walk-in Customer') }}</p>
                </div>

                <form wire:submit="submitReturn" class="mt-4 flex flex-col gap-4">
                    <flux:select wire:model.live="returnType" :label="__('Return action')">
                        <option value="exchange">{{ __('Same product exchange') }}</option>
                        @if ((float) ($this->selectedSale->customer?->due_balance ?? $this->selectedSale->due_amount) > 0)
                            <option value="adjust_due">{{ __('Deduct from customer total due') }} (Rs {{ number_format((float) ($this->selectedSale->customer?->due_balance ?? $this->selectedSale->due_amount), 2) }})</option>
                        @endif
                        <option value="cash_refund">{{ __('Cash refund') }}</option>
                    </flux:select>

                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-950">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-500">{{ __('Return value') }}</span>
                            <span class="font-black text-zinc-950 dark:text-white">{{ __('Rs') }} {{ number_format($this->returnTotal, 2) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span class="text-zinc-500">{{ __('Invoice due') }}</span>
                            <span class="font-bold text-rose-600">{{ __('Rs') }} {{ number_format((float) $this->selectedSale->due_amount, 2) }}</span>
                        </div>
                        @if ($returnType === 'adjust_due')
                            @php($dueReduction = min($this->returnTotal, (float) ($this->selectedSale->customer?->due_balance ?? $this->selectedSale->due_amount)))
                            <p class="mt-3 text-xs font-semibold text-emerald-700">{{ __('Customer due reduction') }}: Rs {{ number_format($dueReduction, 2) }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ __('Remaining customer due') }}: Rs {{ number_format(max(0, (float) ($this->selectedSale->customer?->due_balance ?? $this->selectedSale->due_amount) - $dueReduction), 2) }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ __('Cash refund remainder') }}: Rs {{ number_format(max(0, $this->returnTotal - $dueReduction), 2) }}</p>
                        @endif
                        @if ($this->selectedSale->customer)
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-zinc-500">{{ __('Customer Total due') }}</span>
                                <span class="font-bold text-rose-600">{{ __('Rs') }} {{ number_format((float) $this->selectedSale->customer->due_balance, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <flux:textarea wire:model="returnNotes" :label="__('Notes')" rows="3" placeholder="Reason or condition of returned product." />

                    @if ($errors->any())
                        <ul class="text-xs text-rose-600">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <flux:button type="submit" wire:loading.attr="disabled" wire:target="submitReturn" variant="primary" icon="arrow-uturn-left" class="w-full">
                        {{ $editingReturnId ? __('Save Return Changes') : __('Complete Return') }}
                    </flux:button>
                </form>
            </aside>
        </section>
        <x-sale-return-history :sale="$this->selectedSale" />
    @else
        <section class="rounded-xl border border-dashed border-zinc-200 bg-white p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <flux:icon.arrow-uturn-left class="mx-auto size-10 text-zinc-300" />
            <p class="mt-3 text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ __('Choose an invoice to start a return.') }}</p>
        </section>
    @endif
    <x-sale-return-modal :return="$this->selectedReturn" :allow-manage="true" />
</div>
