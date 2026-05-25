<?php

use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('POS Settings')] class extends Component
{
    public string $pos_layout = 'modern';

    public bool   $card_fee_enabled          = false;
    public float  $card_fee_rate             = 0;
    public string $card_fee_payer            = 'customer';
    public string $card_fee_expense_category = '';
    public bool   $card_fee_record_expense   = true;

    public bool $pos_allow_due_sale       = true;
    public bool $pos_allow_negative_stock = false;
    public bool $pos_show_product_image   = true;
    public bool $pos_enable_hold_order    = true;
    public bool $pos_enable_multiple_price = true;

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->pos_layout = Setting::get('pos_layout', 'modern');

        $this->card_fee_enabled          = Setting::get('card_fee_enabled', '0') === '1';
        $this->card_fee_rate             = (float) Setting::get('card_fee_rate', '0');
        $this->card_fee_payer            = Setting::get('card_fee_payer', 'customer');
        $this->card_fee_expense_category = Setting::get('card_fee_expense_category', '');
        $this->card_fee_record_expense   = Setting::get('card_fee_record_expense', '1') === '1';

        $this->pos_allow_due_sale        = Setting::get('pos_allow_due_sale', '1') === '1';
        $this->pos_allow_negative_stock  = Setting::get('pos_allow_negative_stock', '0') === '1';
        $this->pos_show_product_image    = Setting::get('pos_show_product_image', '1') === '1';
        $this->pos_enable_hold_order     = Setting::get('pos_enable_hold_order', '1') === '1';
        $this->pos_enable_multiple_price = Setting::get('pos_enable_multiple_price', '1') === '1';
    }

    public function saveSettings(): void
    {
        $this->validate([
            'pos_layout'                => 'required|in:modern,classic',
            'card_fee_enabled'          => 'boolean',
            'card_fee_rate'             => 'required|numeric|min:0|max:100',
            'card_fee_payer'            => 'required|in:customer,seller',
            'card_fee_expense_category' => 'nullable|string|max:100',
            'card_fee_record_expense'   => 'boolean',
            'pos_allow_due_sale'        => 'boolean',
            'pos_allow_negative_stock'  => 'boolean',
            'pos_show_product_image'    => 'boolean',
            'pos_enable_hold_order'     => 'boolean',
            'pos_enable_multiple_price' => 'boolean',
        ]);

        Setting::set('pos_layout', $this->pos_layout, 'pos');
        Setting::set('card_fee_enabled', $this->card_fee_enabled ? '1' : '0', 'pos');
        Setting::set('card_fee_rate', (string) $this->card_fee_rate, 'pos');
        Setting::set('card_fee_payer', $this->card_fee_payer, 'pos');
        Setting::set('card_fee_expense_category', $this->card_fee_expense_category, 'pos');
        Setting::set('card_fee_record_expense', $this->card_fee_record_expense ? '1' : '0', 'pos');

        Setting::set('pos_allow_due_sale', $this->pos_allow_due_sale ? '1' : '0', 'pos');
        Setting::set('pos_allow_negative_stock', $this->pos_allow_negative_stock ? '1' : '0', 'pos');
        Setting::set('pos_show_product_image', $this->pos_show_product_image ? '1' : '0', 'pos');
        Setting::set('pos_enable_hold_order', $this->pos_enable_hold_order ? '1' : '0', 'pos');
        Setting::set('pos_enable_multiple_price', $this->pos_enable_multiple_price ? '1' : '0', 'pos');

        ActivityLogger::log('setting_update', 'Updated POS Settings.');
        Flux::toast(variant: 'success', text: __('POS settings saved.'));
    }
}; ?>

<div class="flex flex-col gap-4 sm:gap-6">

    {{-- ── Page Header ── --}}
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-950/40">
                <flux:icon.computer-desktop class="size-4 text-blue-600 dark:text-blue-400" />
            </span>
            {{ __('POS Settings') }}
        </h1>
        <p class="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 ml-9">
            {{ __('Configure POS screen layout, card fees, and checkout behaviour') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="flex flex-col gap-4 sm:gap-6">

        {{-- ── POS Screen Layout ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-purple-100 dark:bg-purple-950/40">
                        <flux:icon.squares-2x2 class="size-3.5 text-purple-600 dark:text-purple-400" />
                    </span>
                    {{ __('POS Screen Layout') }}
                </h2>
            </div>
            <div class="max-w-sm">
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Layout') }}</label>
                <flux:select wire:model="pos_layout">
                    <flux:select.option value="modern">{{ __('Modern (New Fullscreen)') }}</flux:select.option>
                    <flux:select.option value="classic">{{ __('Classic (Split View)') }}</flux:select.option>
                </flux:select>
                <p class="text-[11px] text-zinc-400 mt-1 leading-snug">
                    {{ __('Switch between the old POS screen and the new fullscreen POS screen.') }}
                </p>
            </div>
        </div>

        {{-- ── POS Behaviour ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-violet-100 dark:bg-violet-950/40">
                        <flux:icon.adjustments-horizontal class="size-3.5 text-violet-600 dark:text-violet-400" />
                    </span>
                    {{ __('POS Behaviour') }}
                </h2>
            </div>
            <div class="flex flex-col gap-3">
                @foreach ([
                    ['model' => 'pos_allow_due_sale',        'label' => 'Enable Partial / Due Sales',                   'desc' => 'Allow checkout with due amount remaining on customer account.'],
                    ['model' => 'pos_allow_negative_stock',  'label' => 'Allow Negative Stock Checkout',                'desc' => 'Permit cashiers to complete sales even if digital inventory is 0.'],
                    ['model' => 'pos_show_product_image',    'label' => 'Show Product Images in POS Grid',              'desc' => 'Show thumbnail images on product cards for fast item identification.'],
                    ['model' => 'pos_enable_hold_order',     'label' => 'Enable Hold Order',                            'desc' => 'Allows holding a customer cart to attend the next in queue.'],
                    ['model' => 'pos_enable_multiple_price', 'label' => 'Support Multiple Prices (Retail / Wholesale)', 'desc' => 'Prompt cashier to choose price level if product has custom prices.'],
                ] as $item)
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-3 sm:p-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 leading-tight">{{ __($item['label']) }}</p>
                            <p class="text-xs text-zinc-400 mt-0.5 leading-snug">{{ __($item['desc']) }}</p>
                        </div>
                        <flux:switch wire:model="{{ $item['model'] }}" class="shrink-0" />
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Card Sale Fee ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-950/40">
                        <flux:icon.credit-card class="size-3.5 text-green-600 dark:text-green-400" />
                    </span>
                    {{ __('Card Sale Fee') }}
                </h2>
            </div>
            <div class="flex flex-col gap-4">

                {{-- Enable toggle --}}
                <div class="flex items-center justify-between gap-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-3 sm:p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Enable card fee') }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5 leading-snug">{{ __('Apply a percentage fee for card payments.') }}</p>
                    </div>
                    <flux:switch wire:model="card_fee_enabled" class="shrink-0" />
                </div>

                {{-- Fee fields — stack on mobile, 3 cols on md+ --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Fee Rate (%)') }}</label>
                        <flux:input wire:model="card_fee_rate" type="number" min="0" max="100" step="0.01" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Who pays the fee?') }}</label>
                        <flux:select wire:model="card_fee_payer">
                            <flux:select.option value="customer">{{ __('Customer pays (add to bill)') }}</flux:select.option>
                            <flux:select.option value="seller">{{ __('Seller pays (absorb fee)') }}</flux:select.option>
                        </flux:select>
                    </div>
                    <div class="sm:col-span-2 md:col-span-1">
                        <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Expense Category (seller mode)') }}</label>
                        <flux:input wire:model="card_fee_expense_category" placeholder="-- None --" />
                        <p class="text-[11px] text-zinc-400 mt-1">{{ __('Used only when Seller pays is selected.') }}</p>
                    </div>
                </div>

                {{-- Record expense toggle --}}
                <div class="flex items-center justify-between gap-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-3 sm:p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Record seller card fee as expense') }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5 leading-snug">
                            {{ __('Automatically create an Expense record when payment method is Card and seller pays the fee.') }}
                        </p>
                    </div>
                    <flux:switch wire:model="card_fee_record_expense" class="shrink-0" />
                </div>

                {{-- Info Box --}}
                <div class="rounded-xl border border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/20 p-4">
                    <div class="flex items-start gap-3">
                        <flux:icon.information-circle class="size-5 text-blue-500 dark:text-blue-400 mt-0.5 shrink-0" />
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-blue-900 dark:text-blue-300">{{ __('How it works') }}</p>
                            <ul class="mt-1.5 space-y-1 text-xs text-blue-700 dark:text-blue-400">
                                <li>• <strong>{{ __('Customer pays') }}:</strong> {{ __('fee is added to Total Payable for card payments.') }}</li>
                                <li>• <strong>{{ __('Seller pays') }}:</strong> {{ __('customer total stays unchanged, and the fee can be recorded as an Expense (optional).') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Save ── --}}
        <div class="pb-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save POS Settings') }}
            </flux:button>
        </div>

    </form>
</div>
