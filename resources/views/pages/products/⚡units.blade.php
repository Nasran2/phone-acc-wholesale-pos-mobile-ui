<?php

use App\Models\Unit;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Units')] class extends Component
{
    public string $name = '';
    public string $short_name = '';
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
                Rule::unique(Unit::class, 'name')->ignore($this->editingId),
            ],
            'short_name' => [
                'required',
                'string',
                'max:30',
                Rule::unique(Unit::class, 'short_name')->ignore($this->editingId),
            ],
            'is_active' => ['boolean'],
        ]);

        if ($this->editingId) {
            Unit::query()->whereKey($this->editingId)->update($validated);
            Flux::toast(variant: 'success', text: __('Unit updated.'));
        } else {
            Unit::query()->create($validated);
            Flux::toast(variant: 'success', text: __('Unit added.'));
        }

        $this->reset('name', 'short_name', 'is_active', 'editingId');
    }

    public function edit(int $unitId): void
    {
        $unit = Unit::query()->findOrFail($unitId);
        $this->editingId = $unit->id;
        $this->name = $unit->name;
        $this->short_name = $unit->short_name;
        $this->is_active = (bool) $unit->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset('name', 'short_name', 'is_active', 'editingId');
    }

    public function delete(int $unitId): void
    {
        Unit::query()->whereKey($unitId)->delete();
        Flux::toast(variant: 'success', text: __('Unit removed.'));
    }

    #[Computed]
    public function units()
    {
        return Unit::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('short_name', 'like', "%{$this->search}%");
            })
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="app-card p-4">
    <div class="flex flex-col gap-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Catalog') }}</p>
        <h2 class="font-display text-lg font-semibold text-zinc-900">{{ __('Units') }}</h2>
        <p class="text-sm text-zinc-500">{{ __('Define units used for stock and sales.') }}</p>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
        <flux:input wire:model="name" :label="__('Unit name')" required />
        <flux:input wire:model="short_name" :label="__('Short name')" required />
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
        <flux:input wire:model.live="search" type="search" :label="__('Search')" placeholder="Search units" />
    </div>
</section>

<section class="md:hidden">
    <div class="grid gap-3">
        @forelse ($this->units as $unit)
            <div class="app-card p-4" wire:key="unit-card-{{ $unit->id }}">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-zinc-900">{{ $unit->name }}</p>
                        <p class="text-xs text-zinc-500">{{ $unit->short_name }}</p>
                    </div>
                    <flux:badge size="sm" :color="$unit->is_active ? 'green' : 'zinc'">
                        {{ $unit->is_active ? __('Active') : __('Inactive') }}
                    </flux:badge>
                </div>
                <div class="mt-3 flex items-center justify-end gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="edit({{ $unit->id }})">
                        {{ __('Edit') }}
                    </flux:button>
                    <button
                        type="button"
                        class="text-xs font-semibold text-rose-500"
                        x-on:click.prevent="if (confirm('Remove this unit?')) { $wire.delete({{ $unit->id }}) }"
                    >
                        {{ __('Delete') }}
                    </button>
                </div>
            </div>
        @empty
            <div class="app-card p-6 text-center text-sm text-zinc-500">
                {{ __('No units yet.') }}
            </div>
        @endforelse
    </div>
</section>

<section class="hidden md:block">
    <flux:table class="app-card" container:class="rounded-2xl">
        <flux:table.columns class="bg-white/80">
            <flux:table.column>{{ __('Unit') }}</flux:table.column>
            <flux:table.column>{{ __('Short') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->units as $unit)
                <flux:table.row :key="$unit->id">
                    <flux:table.cell>
                        <p class="text-sm font-semibold text-zinc-900">{{ $unit->name }}</p>
                    </flux:table.cell>
                    <flux:table.cell>{{ $unit->short_name }}</flux:table.cell>
                    <flux:table.cell align="center">
                        <flux:badge size="sm" :color="$unit->is_active ? 'green' : 'zinc'">
                            {{ $unit->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex items-center justify-end gap-2">
                            <flux:button variant="ghost" size="sm" wire:click="edit({{ $unit->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <button
                                type="button"
                                class="text-xs font-semibold text-rose-500"
                                x-on:click.prevent="if (confirm('Remove this unit?')) { $wire.delete({{ $unit->id }}) }"
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
