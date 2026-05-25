<?php

use App\Models\Category;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component
{
    public string $name = '';
    public bool $is_active = true;
    public ?int $editingId = null;
    public string $search = '';

    public function save(): void
    {
        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(Category::class, 'name')->ignore($this->editingId),
            ],
            'is_active' => ['boolean'],
        ]);

        if ($this->editingId) {
            Category::query()->whereKey($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('Category updated.'));
        } else {
            Category::query()->create($validated);
            Flux::toast(variant: 'success', text: __('Category added.'));
        }

        $this->reset('name', 'is_active', 'editingId');
    }

    public function edit(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->is_active = (bool) $category->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset('name', 'is_active', 'editingId');
    }

    public function delete(int $categoryId): void
    {
        Category::query()->whereKey($categoryId)->delete();
        Flux::toast(variant: 'success', text: __('Category removed.'));
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="app-card p-4">
    <div class="flex flex-col gap-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Catalog') }}</p>
        <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Categories') }}</h2>
        <p class="text-sm text-zinc-500">{{ __('Group products by type for quick POS filters.') }}</p>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-[2fr_1fr_auto]">
        <flux:input wire:model="name" :label="__('Category name')" required />
        <flux:switch wire:model="is_active" :label="__('Active')" />
        <div class="flex items-center gap-2">
            <flux:button variant="primary" type="button" wire:click="save">
                {{ $editingId ? __('Update') : __('Add') }}
            </flux:button>
            @if ($editingId)
                <flux:button variant="ghost" type="button" wire:click="cancelEdit">
                    {{ __('Cancel') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="mt-4">
        <flux:input wire:model.live="search" type="search" :label="__('Search')" placeholder="Search categories" />
    </div>
</section>

<section class="md:hidden">
    <div class="grid gap-3">
        @forelse ($this->categories as $category)
            <div class="app-card p-4" wire:key="category-card-{{ $category->id }}">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-zinc-900">{{ $category->name }}</p>
                        <p class="text-xs text-zinc-500">{{ $category->is_active ? __('Active') : __('Inactive') }}</p>
                    </div>
                    <flux:badge size="sm" :color="$category->is_active ? 'green' : 'zinc'">
                        {{ $category->is_active ? __('Active') : __('Inactive') }}
                    </flux:badge>
                </div>
                <div class="mt-3 flex items-center justify-end gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $category->id }})">
                        {{ __('Edit') }}
                    </flux:button>
                    <button
                        type="button"
                        class="text-xs font-semibold text-rose-500"
                        x-on:click.prevent="if (confirm('Remove this category?')) { $wire.delete({{ $category->id }}) }"
                    >
                        {{ __('Delete') }}
                    </button>
                </div>
            </div>
        @empty
            <div class="app-card p-6 text-center text-sm text-zinc-500">
                {{ __('No categories yet.') }}
            </div>
        @endforelse
    </div>
</section>

<section class="hidden md:block">
    <flux:table class="app-card" container:class="rounded-2xl">
        <flux:table.columns class="bg-white/80">
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->categories as $category)
                <flux:table.row :key="$category->id">
                    <flux:table.cell>
                        <p class="text-sm font-semibold text-zinc-900">{{ $category->name }}</p>
                    </flux:table.cell>
                    <flux:table.cell align="center">
                        <flux:badge size="sm" :color="$category->is_active ? 'green' : 'zinc'">
                            {{ $category->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-2">
                            <flux:button variant="ghost" size="sm" wire:click="edit({{ $category->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <button
                                type="button"
                                class="text-xs font-semibold text-rose-500"
                                x-on:click.prevent="if (confirm('Remove this category?')) { $wire.delete({{ $category->id }}) }"
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
