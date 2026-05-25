<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public string $selectedCategory = 'ALL';

    public string $selectedModel = '';

    /**
     * @return array<int, array{id: int, name: string}>
     */
    #[Livewire\Attributes\Computed]
    public function categories(): array
    {
        return $this->rememberFilterData('pos-categories-v1', fn () => Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all());
    }

    /**
     * @return array<int, string>
     */
    #[Livewire\Attributes\Computed]
    public function compatibleModels(): array
    {
        return $this->rememberFilterData('pos-compatible-models-v1', fn () => Product::query()
            ->where('is_active', true)
            ->whereNotNull('compatible_models')
            ->select('compatible_models')
            ->distinct()
            ->orderBy('compatible_models')
            ->pluck('compatible_models')
            ->filter()
            ->values()
            ->all());
    }

    #[Livewire\Attributes\Computed]
    public function products(): Collection
    {
        $searchTerm = trim($this->search);

        return Product::query()
            ->select([
                'id',
                'category_id',
                'brand_id',
                'name',
                'sku',
                'barcode',
                'compatible_models',
                'color',
                'cost_price',
                'selling_price',
                'wholesale_price',
                'stock_quantity',
                'is_active',
            ])
            ->with(['category:id,name'])
            ->where('is_active', true)
            ->when($this->selectedCategory !== 'ALL', fn ($query) => $query->where('category_id', $this->selectedCategory))
            ->when($this->selectedModel !== '', fn ($query) => $query->where('compatible_models', $this->selectedModel))
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($productQuery) use ($searchTerm) {
                    $productQuery->where('name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('sku', 'like', '%' . $searchTerm . '%')
                        ->orWhere('barcode', 'like', '%' . $searchTerm . '%')
                        ->orWhere('compatible_models', 'like', '%' . $searchTerm . '%')
                        ->orWhere('color', 'like', '%' . $searchTerm . '%')
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', '%' . $searchTerm . '%'))
                        ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', '%' . $searchTerm . '%'));
                });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    private function rememberFilterData(string $key, \Closure $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember($key, now()->addMinutes(10), $callback);
    }
};
?>

<div class="min-w-0">
    <div class="grid min-w-0 gap-3 sm:grid-cols-2">
        <div class="flex min-w-0 items-center gap-3 rounded-3xl border border-zinc-200 bg-white px-4 py-3 shadow-[0_12px_35px_rgba(15,23,42,0.05)]">
            <flux:icon.magnifying-glass class="size-5 shrink-0 text-zinc-400" />
            <input
                wire:model.live.debounce.250ms="search"
                type="text"
                placeholder="Search accessories, SKU, barcode..."
                class="min-w-0 w-full bg-transparent text-sm font-semibold text-zinc-950 placeholder:text-zinc-400 focus:outline-none"
            />
        </div>

        <div class="flex min-w-0 items-center gap-3 rounded-3xl border border-zinc-200 bg-white px-4 py-3 shadow-[0_12px_35px_rgba(15,23,42,0.05)]">
            <flux:icon.device-phone-mobile class="size-4 shrink-0 text-zinc-400" />
            <select wire:model.live="selectedModel" class="min-w-0 w-full bg-transparent text-sm font-semibold text-zinc-950 focus:outline-none">
                <option value="">All Mobile Models</option>
                @foreach ($this->compatibleModels as $model)
                    <option value="{{ $model }}">{{ $model }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="scrollbar-none mt-3 flex max-w-full snap-x gap-2 overflow-x-auto pb-2 pt-1">
        <button
            type="button"
            class="snap-start whitespace-nowrap rounded-2xl px-4 py-2.5 text-xs font-black shadow-sm transition"
            :class="@js($selectedCategory) === 'ALL' ? 'bg-zinc-950 text-white shadow-[0_12px_28px_rgba(15,23,42,0.20)]' : 'bg-white text-zinc-600 border border-zinc-200 hover:border-violet-200'"
            wire:click="$set('selectedCategory', 'ALL')"
        >
            {{ __('All Accessories') }}
        </button>
        @foreach ($this->categories as $cat)
            <button
                type="button"
                class="snap-start whitespace-nowrap rounded-2xl px-4 py-2.5 text-xs font-black shadow-sm transition"
                :class="@js($selectedCategory) === @js((string) $cat['id']) ? 'bg-zinc-950 text-white shadow-[0_12px_28px_rgba(15,23,42,0.20)]' : 'bg-white text-zinc-600 border border-zinc-200 hover:border-violet-200'"
                wire:click="$set('selectedCategory', @js((string) $cat['id']))"
                wire:key="pos-category-{{ $cat['id'] }}"
            >
                {{ $cat['name'] }}
            </button>
        @endforeach
    </div>

    <div class="scrollbar-none min-w-0 overflow-y-auto pb-24 lg:max-h-[calc(100vh-14rem)] lg:pb-0">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 md:grid-cols-4 lg:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @forelse ($this->products as $p)
                <div
                    class="group relative flex min-h-[9.25rem] cursor-pointer select-none flex-col justify-between overflow-hidden rounded-[1.25rem] border border-zinc-200 bg-white p-2 shadow-[0_12px_28px_rgba(15,23,42,0.05)] transition data-loading:pointer-events-none data-loading:opacity-70 hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-[0_24px_60px_rgba(124,58,237,0.12)] sm:min-h-44 sm:rounded-[1.75rem] sm:p-4 sm:shadow-[0_18px_45px_rgba(15,23,42,0.06)]"
                    wire:click="$parent.addToCart({{ $p->id }})"
                    wire:island="cart"
                    wire:key="product-pos-{{ $p->id }}"
                >
                    @if ($p->stock_quantity <= 0)
                        <div class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 backdrop-blur-[0.5px]">
                            <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-1 text-[8px] font-bold uppercase tracking-wider text-rose-600 sm:px-3 sm:text-[10px]">Out of stock</span>
                        </div>
                    @endif

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-3">
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-violet-100 via-cyan-50 to-emerald-50 text-sm font-black text-violet-700 ring-1 ring-white sm:h-14 sm:w-14 sm:rounded-2xl sm:text-lg">
                            {{ str($p->name)->substr(0, 1)->upper() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1 text-[8px] sm:gap-2 sm:text-[10px]">
                                <span class="truncate font-black uppercase tracking-wide text-violet-500">{{ $p->category?->name }}</span>
                                <span class="rounded-full bg-emerald-50 px-1.5 py-0.5 font-black text-emerald-600 sm:px-2">{{ $p->stock_quantity }}</span>
                            </div>
                            <h4 class="mt-1 line-clamp-2 text-[11px] font-black leading-tight text-zinc-950 sm:text-sm">{{ $p->name }}</h4>
                            <p class="mt-0.5 truncate text-[9px] font-semibold text-zinc-400 sm:mt-1 sm:text-xs">{{ $p->compatible_models ?: 'Universal fit' }}</p>
                            @if ($p->color)
                                <span class="mt-1 inline-flex max-w-full truncate rounded-full bg-zinc-100 px-1.5 py-0.5 text-[8px] font-bold text-zinc-600 sm:mt-2 sm:px-2 sm:py-1 sm:text-[10px]">{{ $p->color }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="z-20 mt-2 flex items-end justify-between border-t border-zinc-100 pt-2 sm:mt-4 sm:pt-3">
                        <div>
                            <span class="text-[8px] font-black uppercase tracking-wider text-zinc-400 sm:text-[10px]">{{ __('Retail') }}</span>
                            <p class="text-xs font-black text-zinc-950 sm:text-lg">Rs {{ number_format((float) $p->selling_price, 0) }}</p>
                        </div>
                        <div class="grid h-8 w-8 place-items-center rounded-xl bg-zinc-950 text-white shadow-[0_12px_24px_rgba(15,23,42,0.20)] transition group-active:scale-90 sm:h-10 sm:w-10 sm:rounded-2xl">
                            <flux:icon.plus class="size-4 sm:size-5" />
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-[2rem] border border-dashed border-zinc-200 bg-white p-10 text-center shadow-[0_18px_45px_rgba(15,23,42,0.04)]">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-violet-50 text-violet-600">
                        <flux:icon.cube class="size-7" />
                    </div>
                    <h3 class="mt-4 font-display text-lg font-bold text-zinc-950">{{ __('No products found') }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('Seed sample products or clear filters to start selling accessories.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
