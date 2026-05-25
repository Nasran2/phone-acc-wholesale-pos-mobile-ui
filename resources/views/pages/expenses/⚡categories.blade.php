<?php

use App\Models\ExpenseCategory;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Expense Categories')] class extends Component
{
    public string $name = '';

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:80',
        ]);

        ExpenseCategory::query()->firstOrCreate([
            'name' => trim($this->name),
        ]);

        $this->reset('name');
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function delete(int $id): void
    {
        ExpenseCategory::query()->whereKey($id)->delete();
        Flux::toast(variant: 'success', text: __('Category removed.'));
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::query()->orderBy('name')->get();
    }
};
?>

<div class="flex flex-col gap-5">
    <section class="app-card p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-violet-600 dark:text-violet-300">{{ __('Expenses') }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __('Expense Categories') }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">{{ __('Manage the categories used when recording expenses.') }}</p>
            </div>
            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" icon="list-bullet" href="{{ route('expenses.index') }}" wire:navigate>
                    {{ __('Expenses') }}
                </flux:button>
                <flux:button type="button" variant="primary" icon="plus" href="{{ route('expenses.create') }}" wire:navigate>
                    {{ __('Add Expense') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section class="app-card p-5">
        <form wire:submit="save" class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="flex-1">
                <flux:input wire:model.live="name" :label="__('Category Name')" placeholder="e.g. Utilities" />
            </div>
            <flux:button type="submit" variant="primary" icon="plus" class="w-full sm:w-auto">
                {{ __('Add Category') }}
            </flux:button>
        </form>
    </section>

    <section class="app-card overflow-hidden">
        <div class="border-b border-zinc-100 p-4 dark:border-zinc-800 sm:p-5">
            <h2 class="font-display text-base font-semibold text-zinc-950 dark:text-white">{{ __('Categories') }}</h2>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $this->categories->count() }} {{ __('categories') }}</p>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($this->categories as $category)
                <div class="flex items-center justify-between gap-3 p-4 sm:p-5" wire:key="expense-category-{{ $category->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $category->name }}</p>
                    </div>
                    <flux:button type="button" variant="ghost" icon="trash" wire:click="delete({{ $category->id }})">
                        {{ __('Delete') }}
                    </flux:button>
                </div>
            @empty
                <div class="p-10 text-center text-sm font-medium text-zinc-400">
                    {{ __('No categories found.') }}
                </div>
            @endforelse
        </div>
    </section>
</div>
