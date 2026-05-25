<div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
    <div class="space-y-6">
        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Basics</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Product details') }}</h2>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.name" :label="__('Product name')" required />
                <flux:input wire:model="form.sku" :label="__('SKU / Code')" placeholder="Leave blank to auto-generate" />
                <flux:input wire:model="form.barcode" :label="__('Barcode')" placeholder="Leave blank to sync with SKU" />
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="relative">
                    <flux:select wire:model="form.category_id" :label="__('Category')">
                        <flux:select.option value="">{{ __('Uncategorized') }}</flux:select.option>
                        @foreach ($this->categories as $category)
                            <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <button type="button" @click="let n = prompt('Enter new category name:'); if(n) $wire.quickAddCategory(n)" class="absolute right-0 top-0 text-[10px] sm:text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition px-1">+ Add New</button>
                </div>
                <div class="relative">
                    <flux:select wire:model="form.brand_id" :label="__('Brand')">
                        <flux:select.option value="">{{ __('No brand') }}</flux:select.option>
                        @foreach ($this->brands as $brand)
                            <flux:select.option :value="$brand->id">{{ $brand->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <button type="button" @click="let n = prompt('Enter new brand name:'); if(n) $wire.quickAddBrand(n)" class="absolute right-0 top-0 text-[10px] sm:text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition px-1">+ Add New</button>
                </div>
                <div class="relative">
                    <flux:select wire:model="form.unit_id" :label="__('Unit')">
                        <flux:select.option value="">{{ __('No unit') }}</flux:select.option>
                        @foreach ($this->units as $unit)
                            <flux:select.option :value="$unit->id">{{ $unit->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <button type="button" @click="let n = prompt('Enter new unit name:'); if(n) $wire.quickAddUnit(n)" class="absolute right-0 top-0 text-[10px] sm:text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition px-1">+ Add New</button>
                </div>
                <flux:input wire:model="form.compatible_models" :label="__('Compatible models')" />
            </div>
        </div>

        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Pricing</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Price setup') }}</h2>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="form.cost_price" type="number" step="0.01" :label="__('Cost price')" required />
                <flux:input wire:model="form.selling_price" @input="$wire.set('form.wholesale_price', $event.target.value)" type="number" step="0.01" :label="__('Selling price')" required />
                <flux:input wire:model="form.wholesale_price" type="number" step="0.01" :label="__('Wholesale price')" />
            </div>
        </div>

        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Inventory</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Stock control') }}</h2>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.stock_quantity" type="number" min="0" :label="__('Stock quantity')" required />
                <flux:input wire:model="form.minimum_stock" type="number" min="0" :label="__('Minimum stock alert')" />
            </div>
        </div>

        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Warranty</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Warranty setup') }}</h2>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:switch wire:model="form.warranty_enabled" :label="__('Warranty available')" />
                @if ($form->warranty_enabled)
                    <flux:input
                        wire:model="form.warranty_period_days"
                        type="number"
                        min="1"
                        :label="__('Warranty days')"
                        required
                    />
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Image</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Product photo') }}</h2>
            </div>
            <div class="mt-4 flex items-center justify-center rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-6">
                @if ($form->image)
                    <img
                        src="{{ $form->image->temporaryUrl() }}"
                        alt="{{ __('Product image preview') }}"
                        class="h-40 w-full rounded-2xl object-cover"
                    />
                @elseif ($form->image_path)
                    <img
                        src="{{ Storage::url($form->image_path) }}"
                        alt="{{ __('Product image') }}"
                        class="h-40 w-full rounded-2xl object-cover"
                    />
                @else
                    <div class="text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 cyber-glow-indigo">
                            <flux:icon.photo class="size-6" />
                        </div>
                        <p class="mt-3 text-xs text-zinc-500">{{ __('Add a product image') }}</p>
                    </div>
                @endif
            </div>
            <div class="mt-4">
                <flux:input wire:model="form.image" type="file" :label="__('Upload image')" />
            </div>
        </div>

        <div class="app-card p-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</p>
                <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Availability') }}</h2>
            </div>
            <div class="mt-4 flex items-center justify-between">
                <flux:switch wire:model="form.is_active" :label="__('Active product')" />
                <flux:badge size="sm" :color="$form->is_active ? 'green' : 'zinc'">
                    {{ $form->is_active ? __('Active') : __('Inactive') }}
                </flux:badge>
            </div>
        </div>
    </div>
</div>

<div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
    <flux:button variant="ghost" :href="route('products.index')" wire:navigate>
        {{ __('Cancel') }}
    </flux:button>
    <flux:button variant="primary" type="submit">
        {{ $submitLabel }}
    </flux:button>
</div>
