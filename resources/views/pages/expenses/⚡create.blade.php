<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Add Expense')] class extends Component
{
    use WithFileUploads;

    public string $category = '';
    public string $amount = '';
    public string $date = '';
    public string $payment_method = 'cash';
    public string $reference = '';
    public string $notes = '';
    public $attachment = null;

    public bool $categoryModalOpen = false;
    public string $newCategoryName = '';

    public function mount(): void
    {
        $this->date = today()->toDateString();

        $firstCategory = ExpenseCategory::query()->orderBy('name')->value('name');
        $this->category = $firstCategory ?: 'Utility Bills';
    }

    public function openCategoryModal(): void
    {
        $this->newCategoryName = '';
        $this->categoryModalOpen = true;
    }

    public function saveCategory(): void
    {
        $this->validate([
            'newCategoryName' => 'required|string|max:80',
        ]);

        $name = trim($this->newCategoryName);

        ExpenseCategory::query()->firstOrCreate([
            'name' => $name,
        ]);

        $this->category = $name;
        $this->categoryModalOpen = false;
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    public function saveExpense(): void
    {
        $this->validate([
            'category' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'payment_method' => 'required|string',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|max:2048|mimes:jpg,jpeg,png,pdf',
        ]);

        $filePath = null;
        if ($this->attachment) {
            $filePath = $this->attachment->store('expenses', 'public');
        }

        Expense::query()->create([
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'date' => $this->date,
            'payment_method' => $this->payment_method,
            'reference' => filled($this->reference) ? $this->reference : null,
            'notes' => filled($this->notes) ? $this->notes : null,
            'attachment_path' => $filePath,
        ]);

        ActivityLogger::log('expense_create', "Recorded new expense: {$this->category} of Rs ".(float) $this->amount.'.');
        Flux::toast(variant: 'success', text: __('Expense captured successfully.'));

        $this->reset('amount', 'reference', 'notes', 'attachment');
        $this->date = today()->toDateString();
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::query()->orderBy('name')->pluck('name')->all();
    }
};
?>

<div class="flex flex-col gap-5">
    <section class="app-card p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-violet-600 dark:text-violet-300">{{ __('Expenses') }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __('Add Expense') }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">{{ __('Record overhead payments and optionally upload receipt vouchers for audits.') }}</p>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" icon="list-bullet" href="{{ route('expenses.index') }}" wire:navigate>
                    {{ __('Expenses') }}
                </flux:button>
                <flux:button type="button" variant="ghost" icon="tag" href="{{ route('expenses.categories') }}" wire:navigate>
                    {{ __('Categories') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section class="app-card p-5">
        <form wire:submit="saveExpense" class="grid gap-4 lg:grid-cols-2">
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <flux:select wire:model.live="category" :label="__('Expense Category')">
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button type="button" variant="ghost" icon="plus" square tooltip="{{ __('Add Category') }}" wire:click="openCategoryModal" class="mb-[2px]" />
            </div>

            <flux:input wire:model.live="amount" :label="__('Amount (Rs)')" type="number" step="0.01" required />
            <flux:input wire:model.live="date" :label="__('Expense Date')" type="date" required />

            <flux:select wire:model.live="payment_method" :label="__('Payment Method')">
                <option value="cash">{{ __('Cash') }}</option>
                <option value="card">{{ __('Card') }}</option>
                <option value="qr">{{ __('QR') }}</option>
                <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                <option value="cheque">{{ __('Cheque') }}</option>
            </flux:select>

            <flux:input wire:model.live="reference" :label="__('Reference (Optional)')" placeholder="e.g. BILL-992" />
            <div class="lg:col-span-2">
                <flux:textarea wire:model.live="notes" :label="__('Notes (Optional)')" rows="3" placeholder="Brief details about the expense." />
            </div>

            <div class="lg:col-span-2">
                <flux:input wire:model.live="attachment" :label="__('Attachment (Optional)')" type="file" />
                <p class="mt-1 text-[10px] text-zinc-400">{{ __('Max 2MB. JPG, PNG, PDF.') }}</p>
            </div>

            <div class="lg:col-span-2">
                <flux:button type="submit" variant="primary" icon="plus" class="w-full">
                    {{ __('Save Expense') }}
                </flux:button>
            </div>
        </form>
    </section>

    <flux:modal wire:model.self="categoryModalOpen">
        <div class="space-y-4 w-full max-w-2xl">
            <div>
                <h3 class="font-display text-lg font-semibold text-zinc-950 dark:text-white">{{ __('Add Expense Category') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Create a new category and select it for this expense.') }}</p>
            </div>

            <flux:input wire:model.live="newCategoryName" :label="__('Category Name')" placeholder="e.g. Shop Maintenance" />

            <div class="flex gap-2 justify-end">
                <flux:button type="button" variant="ghost" wire:click="$set('categoryModalOpen', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="primary" icon="plus" wire:click="saveCategory">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
