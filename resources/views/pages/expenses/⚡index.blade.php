<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Expenses')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cat')]
    public string $category = 'all';

    #[Url(as: 'range')]
    public string $dateRange = '7days';

    #[Url(as: 'from')]
    public ?string $customStartDate = null;

    #[Url(as: 'to')]
    public ?string $customEndDate = null;

    #[Url(as: 'method')]
    public string $paymentMethod = 'all';

    public function mount(): void
    {
        $this->customStartDate ??= today()->toDateString();
        $this->customEndDate ??= today()->toDateString();
    }

    public function clearFilters(): void
    {
        $this->resetPage();
        $this->search = '';
        $this->category = 'all';
        $this->dateRange = '7days';
        $this->customStartDate = today()->toDateString();
        $this->customEndDate = today()->toDateString();
        $this->paymentMethod = 'all';
    }

    public function deleteExpense(int $id): void
    {
        $expense = Expense::query()->findOrFail($id);

        if ($expense->attachment_path && Storage::disk('public')->exists($expense->attachment_path)) {
            Storage::disk('public')->delete($expense->attachment_path);
        }

        $expense->delete();
        Flux::toast(variant: 'success', text: __('Expense removed.'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    #[Computed]
    public function period(): array
    {
        return match ($this->dateRange) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            '30days' => [today()->subDays(30)->toDateString(), today()->toDateString()],
            'custom' => [$this->customStartDate ?: today()->toDateString(), $this->customEndDate ?: today()->toDateString()],
            default => [today()->subDays(7)->toDateString(), today()->toDateString()],
        };
    }

    #[Computed]
    public function categories(): array
    {
        return ExpenseCategory::query()->orderBy('name')->pluck('name')->all();
    }

    #[Computed]
    public function expenses()
    {
        [$start, $end] = $this->period;

        return Expense::query()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->when($this->paymentMethod !== 'all', fn ($q) => $q->where('payment_method', $this->paymentMethod))
            ->when(filled($this->search), function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($q) use ($term): void {
                    $q->where('category', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(12);
    }

    #[Computed]
    public function filteredTotal(): float
    {
        [$start, $end] = $this->period;

        return (float) Expense::query()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->when($this->category !== 'all', fn ($q) => $q->where('category', $this->category))
            ->when($this->paymentMethod !== 'all', fn ($q) => $q->where('payment_method', $this->paymentMethod))
            ->when(filled($this->search), function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($q) use ($term): void {
                    $q->where('category', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            })
            ->sum('amount');
    }

    public function methodLabel(string $method): string
    {
        return str($method)->replace('_', ' ')->headline()->toString();
    }

    public function displayDate(string $date): string
    {
        return Carbon::parse($date)->format('Y-m-d');
    }
};
?>

@php
    [$startDate, $endDate] = $this->period;
@endphp

<div class="flex flex-col gap-5">
    <section class="app-card p-4 sm:p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-violet-600 dark:text-violet-300">{{ __('Expenses') }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __('Expenses') }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">{{ __('All recorded expenses with filters.') }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                <flux:button type="button" variant="primary" icon="plus" href="{{ route('expenses.create') }}" wire:navigate class="w-full sm:w-auto">
                    {{ __('Add Expense') }}
                </flux:button>
                <flux:button type="button" variant="ghost" icon="tag" href="{{ route('expenses.categories') }}" wire:navigate class="w-full sm:w-auto">
                    {{ __('Categories') }}
                </flux:button>
                <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="clearFilters" class="w-full sm:w-auto">
                    {{ __('Reset') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border p-4 border-rose-100 bg-rose-50/60 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">
            <p class="text-[10px] font-black uppercase tracking-wider opacity-75">{{ __('Filtered Total') }}</p>
            <p class="mt-1 font-display text-xl font-bold">Rs {{ number_format($this->filteredTotal, 2) }}</p>
            <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider opacity-70">{{ $startDate }} {{ __('to') }} {{ $endDate }}</p>
        </div>
        <div class="rounded-2xl border p-4 border-zinc-100 bg-zinc-50/70 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-300">
            <p class="text-[10px] font-black uppercase tracking-wider opacity-75">{{ __('Records') }}</p>
            <p class="mt-1 font-display text-xl font-bold">{{ number_format($this->expenses->total()) }}</p>
        </div>
    </section>

    <section class="app-card p-4">
        <div class="grid gap-3 lg:grid-cols-[1fr_auto]">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <flux:select wire:model.live="dateRange" :label="__('Period')">
                    <option value="today">{{ __('Today') }}</option>
                    <option value="yesterday">{{ __('Yesterday') }}</option>
                    <option value="7days">{{ __('Last 7 Days') }}</option>
                    <option value="30days">{{ __('Last 30 Days') }}</option>
                    <option value="custom">{{ __('Custom Range') }}</option>
                </flux:select>

                <div x-data x-show="$wire.dateRange === 'custom'" x-cloak>
                    <flux:input wire:model.live="customStartDate" type="date" :label="__('Start Date')" />
                </div>

                <div x-data x-show="$wire.dateRange === 'custom'" x-cloak>
                    <flux:input wire:model.live="customEndDate" type="date" :label="__('End Date')" />
                </div>

                <flux:select wire:model.live="category" :label="__('Category')">
                    <option value="all">{{ __('All Categories') }}</option>
                    @foreach ($this->categories as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="paymentMethod" :label="__('Payment Method')">
                    <option value="all">{{ __('All Methods') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="qr">{{ __('QR') }}</option>
                    <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                    <option value="cheque">{{ __('Cheque') }}</option>
                </flux:select>
            </div>

            <div class="min-w-0 lg:w-80">
                <flux:input wire:model.live.debounce.350ms="search" :label="__('Search')" placeholder="Category, reference, notes..." />
            </div>
        </div>
    </section>

    <section class="app-card overflow-hidden">
        <div class="border-b border-zinc-100 p-4 dark:border-zinc-800 sm:p-5">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="font-display text-base font-semibold text-zinc-950 dark:text-white">{{ __('Expense List') }}</h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ number_format($this->expenses->total()) }} {{ __('records') }} · {{ $startDate }} {{ __('to') }} {{ $endDate }}
                    </p>
                </div>
            </div>
        </div>

        <div class="hidden overflow-x-auto md:block">
            <table class="w-full border-collapse text-left text-xs">
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-800">
                        <th class="px-4 py-3 font-bold uppercase tracking-wider text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-3 font-bold uppercase tracking-wider text-left">{{ __('Category') }}</th>
                        <th class="px-4 py-3 font-bold uppercase tracking-wider text-left">{{ __('Reference') }}</th>
                        <th class="px-4 py-3 font-bold uppercase tracking-wider text-left">{{ __('Method') }}</th>
                        <th class="px-4 py-3 font-bold uppercase tracking-wider text-right">{{ __('Amount') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                    @forelse ($this->expenses as $expense)
                        <tr wire:key="expense-row-{{ $expense->id }}" class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3.5 text-left">{{ $this->displayDate((string) $expense->date) }}</td>
                            <td class="px-4 py-3.5 text-left">{{ $expense->category }}</td>
                            <td class="px-4 py-3.5 text-left">{{ $expense->reference ?: '-' }}</td>
                            <td class="px-4 py-3.5 text-left">{{ $this->methodLabel($expense->payment_method) }}</td>
                            <td class="px-4 py-3.5 text-right text-rose-600">{{ __('Rs') }} {{ number_format((float) $expense->amount, 2) }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <flux:button type="button" variant="ghost" icon="trash" wire:click="deleteExpense({{ $expense->id }})">
                                    {{ __('Delete') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm font-medium text-zinc-400">
                                {{ __('No expenses found for the selected filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-3 p-3 md:hidden">
            @forelse ($this->expenses as $expense)
                <article class="rounded-xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" wire:key="expense-card-{{ $expense->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-zinc-950 dark:text-white">{{ $expense->category }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $this->displayDate((string) $expense->date) }}</p>
                        </div>
                        <p class="shrink-0 text-sm font-black text-rose-600">{{ __('Rs') }} {{ number_format((float) $expense->amount, 2) }}</p>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div class="min-w-0 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60">
                            <dt class="font-semibold uppercase tracking-wider text-zinc-400">{{ __('Method') }}</dt>
                            <dd class="mt-1 truncate font-bold text-zinc-800 dark:text-zinc-100">{{ $this->methodLabel($expense->payment_method) }}</dd>
                        </div>
                        <div class="min-w-0 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60">
                            <dt class="font-semibold uppercase tracking-wider text-zinc-400">{{ __('Reference') }}</dt>
                            <dd class="mt-1 truncate font-bold text-zinc-800 dark:text-zinc-100">{{ $expense->reference ?: '-' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3">
                        <flux:button type="button" variant="ghost" icon="trash" wire:click="deleteExpense({{ $expense->id }})" class="w-full">
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm font-medium text-zinc-400 dark:border-zinc-800">
                    {{ __('No expenses found for the selected filters.') }}
                </div>
            @endforelse

            <div>
                {{ $this->expenses->links() }}
            </div>
        </div>

        <div class="hidden p-4 md:block">
            {{ $this->expenses->links() }}
        </div>
    </section>
</div>

