<?php

use App\Models\Product;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Product details')] class extends Component
{
    public Product $product;

    public function mount(Product $product): void
    {
        $this->product = $product->load(['category', 'brand', 'unit']);
    }
}; ?>

@push('page-actions')
    <flux:button variant="ghost" :href="route('products.edit', $product)" wire:navigate>
        {{ __('Edit') }}
    </flux:button>
    <flux:button variant="primary" :href="route('products.create')" wire:navigate>
        {{ __('Add product') }}
    </flux:button>
@endpush

<div class="space-y-6">
    <section class="app-card p-6">
        <div class="flex flex-col gap-6 lg:flex-row">
            <div class="flex h-40 w-40 items-center justify-center overflow-hidden rounded-3xl bg-violet-50 dark:bg-violet-950/30">
                @if ($product->image_path)
                    <img
                        src="{{ Storage::url($product->image_path) }}"
                        alt="{{ $product->name }}"
                        class="h-full w-full object-cover"
                    />
                @else
                    <span class="font-display text-4xl font-semibold text-violet-600 dark:text-violet-400">
                        {{ str($product->name)->substr(0, 1)->upper() }}
                    </span>
                @endif
            </div>
            <div class="flex-1 space-y-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Product') }}</p>
                    <h2 class="font-display text-2xl font-semibold text-zinc-900">{{ $product->name }}</h2>
                    <p class="text-sm text-zinc-500">SKU {{ $product->sku }}{{ $product->barcode ? ' · '.$product->barcode : '' }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:badge size="sm" :color="$product->is_active ? 'green' : 'zinc'">
                        {{ $product->is_active ? __('Active') : __('Inactive') }}
                    </flux:badge>
                    <span class="app-chip">{{ $product->category?->name ?? __('Uncategorized') }}</span>
                    <span class="app-chip">{{ $product->brand?->name ?? __('No brand') }}</span>
                    <span class="app-chip">{{ $product->unit?->name ?? __('No unit') }}</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="app-card-muted p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Selling price') }}</p>
                        <p class="font-display text-xl font-semibold text-zinc-900">Rs {{ number_format($product->selling_price, 2) }}</p>
                        <p class="text-xs text-zinc-500">{{ __('Cost') }} Rs {{ number_format($product->cost_price, 2) }}</p>
                    </div>
                    <div class="app-card-muted p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Stock') }}</p>
                        <p class="font-display text-xl font-semibold text-zinc-900">{{ $product->stock_quantity }}</p>
                        <p class="text-xs text-zinc-500">{{ __('Min alert') }} {{ $product->minimum_stock }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="app-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Compatibility') }}</p>
            <p class="mt-2 text-sm text-zinc-700">
                {{ $product->compatible_models ?: __('Not specified') }}
            </p>
        </div>
        <div class="app-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Color') }}</p>
            <p class="mt-2 text-sm text-zinc-700">{{ $product->color ?: __('Not specified') }}</p>
        </div>
        <div class="app-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Warranty') }}</p>
            <p class="mt-2 text-sm text-zinc-700">
                {{ $product->warranty_enabled ? $product->warranty_period_days.' days' : __('No warranty') }}
            </p>
        </div>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
        <flux:button variant="ghost" :href="route('products.index')" wire:navigate>
            {{ __('Back to products') }}
        </flux:button>
        <flux:button variant="primary" :href="route('products.edit', $product)" wire:navigate>
            {{ __('Edit product') }}
        </flux:button>
    </div>
</div>
