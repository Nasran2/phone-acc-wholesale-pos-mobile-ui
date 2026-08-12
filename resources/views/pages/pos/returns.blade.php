<?php

use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Services\SaleReturnService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('POS Returns')] class extends Component
{
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
    }

    public function updatedSearch(): void
    {
        $this->selectedSaleId = null;
        $this->returnItems = [];
    }

    public function selectSale(int $saleId): void
    {
        $sale = Sale::query()->with(['items.product', 'returns.items'])->findOrFail($saleId);

        $this->selectedSaleId = $sale->id;
        $this->returnItems = [];

        foreach ($sale->items as $item) {
            $alreadyReturned = (int) SaleReturnItem::query()
                ->whereHas('returnLog', fn ($query) => $query->where('sale_id', $sale->id))
                ->where('product_id', $item->product_id)
                ->sum('quantity');

            $maxReturnable = max(0, $item->quantity - $alreadyReturned);

            if ($maxReturnable > 0 && $item->product_id) {
                $this->returnItems[$item->product_id] = [
                    'name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'quantity' => 0,
                    'refund_price' => (float) $item->selling_price,
                    'max' => $maxReturnable,
                    'subtotal' => 0.00,
                    'stock' => (int) ($item->product?->stock_quantity ?? 0),
                ];
            }
        }

        $this->returnType = (float) $sale->due_amount > 0 ? 'adjust_due' : 'exchange';
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
        $this->returnItems[$productId]['subtotal'] = round($quantity * (float) $this->returnItems[$productId]['refund_price'], 2);
    }

    public function submitReturn(SaleReturnService $returnService): void
    {
        $this->validate([
            'selectedSaleId' => 'required|exists:sales,id',
            'returnItems' => 'required|array',
            'returnType' => 'required|in:cash_refund,adjust_due,exchange',
            'returnNotes' => 'nullable|string|max:1000',
        ]);

        $sale = Sale::query()->with(['customer', 'items.product'])->findOrFail($this->selectedSaleId);

        $returnService->process($sale, $this->returnItems, $this->returnType, $this->returnNotes);

        Flux::toast(variant: 'success', text: __('Return completed successfully.'));

        $this->selectSale($sale->id);
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
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function selectedSale(): ?Sale
    {
        if (! $this->selectedSaleId) {
            return null;
        }

        return Sale::query()
            ->with(['customer', 'items.product', 'returns.items.product'])
            ->find($this->selectedSaleId);
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

    <section class="app-card p-3 sm:p-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search invoice, customer, or phone..." />

        <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($this->sales as $sale)
                <button
                    type="button"
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

    @if ($this->selectedSale)
        <section class="grid gap-4 lg:grid-cols-[1fr_360px]">
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
                        @if ($this->selectedSale->customer && $this->selectedSale->customer->due_balance > 0)
                            <option value="adjust_due">{{ __('Reduce customer due') }} (Rs {{ number_format($this->selectedSale->customer->due_balance, 2) }})</option>
                        @elseif ($this->selectedSale->due_amount > 0)
                            <option value="adjust_due">{{ __('Reduce invoice due') }} (Rs {{ number_format($this->selectedSale->due_amount, 2) }})</option>
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
                        @if ($this->selectedSale->customer)
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-zinc-500">{{ __('Customer Total due') }}</span>
                                <span class="font-bold text-rose-600">{{ __('Rs') }} {{ number_format((float) $this->selectedSale->customer->due_balance, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <flux:textarea wire:model="returnNotes" :label="__('Notes')" rows="3" placeholder="Reason or condition of returned product." />

                    <flux:button type="submit" variant="primary" icon="arrow-uturn-left" class="w-full">
                        {{ __('Complete Return') }}
                    </flux:button>
                </form>
            </aside>
        </section>
    @else
        <section class="rounded-xl border border-dashed border-zinc-200 bg-white p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <flux:icon.arrow-uturn-left class="mx-auto size-10 text-zinc-300" />
            <p class="mt-3 text-sm font-bold text-zinc-700 dark:text-zinc-200">{{ __('Choose an invoice to start a return.') }}</p>
        </section>
    @endif
</div>
