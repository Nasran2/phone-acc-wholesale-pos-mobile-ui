<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Products')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $categoryId = null;
    public ?int $brandId = null;
    public string $status = 'all';
    public string $stock = 'all';
    public int $perPage = 12;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatingBrandId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingStock(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function brands()
    {
        return Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function products()
    {
        return Product::query()
            ->with(['category', 'brand', 'unit'])
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('name', 'like', "%{$this->search}%")
                        ->orWhere('sku', 'like', "%{$this->search}%")
                        ->orWhere('barcode', 'like', "%{$this->search}%");
                });
            })
            ->when($this->categoryId, fn ($query) => $query->where('category_id', $this->categoryId))
            ->when($this->brandId, fn ($query) => $query->where('brand_id', $this->brandId))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($this->stock === 'low', function ($query) {
                $query->whereColumn('stock_quantity', '<=', 'minimum_stock')
                    ->where('stock_quantity', '>', 0);
            })
            ->when($this->stock === 'out', fn ($query) => $query->where('stock_quantity', 0))
            ->when($this->stock === 'in', fn ($query) => $query->where('stock_quantity', '>', 0))
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function deleteProduct(int $productId): void
    {
        Product::query()->whereKey($productId)->delete();

        Flux::toast(variant: 'success', text: __('Product removed.'));
    }
}; ?>

@push('page-actions')
    <flux:button variant="primary" :href="route('products.create')" wire:navigate>
        {{ __('New product') }}
    </flux:button>
@endpush

<div class="flex flex-col gap-6">
    <section class="app-card p-4">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Inventory</p>
                    <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Product catalog') }}</h2>
                    <p class="text-xs text-zinc-500">{{ $this->products->total() }} {{ __('items') }}</p>
                </div>
                <flux:button variant="primary" class="sm:hidden" :href="route('products.create')" wire:navigate>
                    {{ __('Add product') }}
                </flux:button>
            </div>

            <div class="grid gap-3 lg:grid-cols-[2fr_repeat(4,minmax(0,1fr))]">
                <flux:input
                    wire:model.live="search"
                    type="search"
                    :label="__('Search')"
                    placeholder="Search by name, SKU, or barcode"
                />

                <flux:select wire:model.live="categoryId" placeholder="All categories">
                    <flux:select.option value="">All categories</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="brandId" placeholder="All brands">
                    <flux:select.option value="">All brands</flux:select.option>
                    @foreach ($this->brands as $brand)
                        <flux:select.option :value="$brand->id">{{ $brand->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="status" placeholder="Status">
                    <flux:select.option value="all">All status</flux:select.option>
                    <flux:select.option value="active">Active</flux:select.option>
                    <flux:select.option value="inactive">Inactive</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="stock" placeholder="Stock">
                    <flux:select.option value="all">All stock</flux:select.option>
                    <flux:select.option value="in">In stock</flux:select.option>
                    <flux:select.option value="low">Low stock</flux:select.option>
                    <flux:select.option value="out">Out of stock</flux:select.option>
                </flux:select>
            </div>
        </div>
    </section>

    <section class="md:hidden">
        <div class="grid gap-3">
            @forelse ($this->products as $product)
                <div class="app-card p-4" wire:key="product-card-{{ $product->id }}">
                    <div class="flex items-center gap-3">
                        <div class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl bg-violet-50 dark:bg-violet-950/30">
                            @if ($product->image_path)
                                <img
                                    src="{{ Storage::url($product->image_path) }}"
                                    alt="{{ $product->name }}"
                                    class="h-full w-full object-cover"
                                />
                            @else
                                <span class="font-display text-lg font-semibold text-violet-600 dark:text-violet-400">
                                    {{ str($product->name)->substr(0, 1)->upper() }}
                                </span>
                            @endif
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-zinc-900">{{ $product->name }}</p>
                            <p class="text-xs text-zinc-500">SKU {{ $product->sku }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <span class="app-chip">Rs {{ number_format($product->selling_price, 2) }}</span>
                                <span class="app-chip">Stock {{ $product->stock_quantity }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <flux:badge size="sm" :color="$product->is_active ? 'green' : 'zinc'">
                            {{ $product->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                        <div class="flex items-center gap-2">
                            <flux:button variant="ghost" size="sm" :href="route('products.show', $product)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" :href="route('products.edit', $product)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                            <button
                                type="button"
                                class="text-xs font-semibold text-rose-500"
                                x-on:click.prevent="if (confirm('Remove this product?')) { $wire.deleteProduct({{ $product->id }}) }"
                            >
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="app-card p-6 text-center text-sm text-zinc-500">
                    {{ __('No products match your filters.') }}
                </div>
            @endforelse
        </div>
    </section>

    <section class="hidden md:block">
        <flux:table :paginate="$this->products" pagination:scroll-to="#product-table" class="app-card" container:class="rounded-2xl" id="product-table">
            <flux:table.columns class="bg-white/80">
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('SKU') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Price') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Stock') }}</flux:table.column>
                <flux:table.column align="center">{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->products as $product)
                    <flux:table.row :key="$product->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl bg-violet-50 dark:bg-violet-950/30">
                                @if ($product->image_path)
                                    <img
                                        src="{{ Storage::url($product->image_path) }}"
                                        alt="{{ $product->name }}"
                                        class="h-full w-full object-cover"
                                    />
                                @else
                                    <span class="font-display text-sm font-semibold text-violet-600 dark:text-violet-400">
                                        {{ str($product->name)->substr(0, 1)->upper() }}
                                    </span>
                                @endif
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-zinc-900">{{ $product->name }}</p>
                                <p class="text-xs text-zinc-500">
                                    {{ $product->category?->name ?? __('Uncategorized') }}
                                </p>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $product->sku }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong">
                            Rs {{ number_format($product->selling_price, 2) }}
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <span @class([
                                'text-sm font-semibold',
                                'text-rose-500' => $product->stock_quantity <= $product->minimum_stock,
                                'text-zinc-700' => $product->stock_quantity > $product->minimum_stock,
                            ])>
                                {{ $product->stock_quantity }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="center">
                            <flux:badge size="sm" :color="$product->is_active ? 'green' : 'zinc'">
                                {{ $product->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button variant="ghost" size="sm" :href="route('products.show', $product)" wire:navigate>
                                    {{ __('View') }}
                                </flux:button>
                                <flux:button variant="ghost" size="sm" :href="route('products.edit', $product)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-rose-500"
                                    x-on:click.prevent="if (confirm('Remove this product?')) { $wire.deleteProduct({{ $product->id }}) }"
                                >
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>
</div>
