<?php

use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('General Settings')] class extends Component
{
    public string $currency_symbol   = 'Rs';
    public string $currency_position = 'before';
    public int    $decimal_places    = 2;

    public string $date_format = 'Y-m-d';
    public string $time_format = '12';
    public string $timezone    = 'Asia/Colombo';

    public string $language      = 'en';
    public int    $items_per_page = 10;

    public bool $low_stock_warning = true;

    public bool  $vat_enabled = false;
    public float $vat_rate    = 0;

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->currency_symbol   = Setting::get('currency_symbol', 'Rs');
        $this->currency_position = Setting::get('currency_position', 'before');
        $this->decimal_places    = (int) Setting::get('decimal_places', '2');

        $this->date_format = Setting::get('date_format', 'Y-m-d');
        $this->time_format = Setting::get('time_format', '12');
        $this->timezone    = Setting::get('timezone', 'Asia/Colombo');

        $this->language       = Setting::get('language', 'en');
        $this->items_per_page = (int) Setting::get('items_per_page', '10');

        $this->low_stock_warning = Setting::get('low_stock_warning', '1') === '1';

        $this->vat_enabled = Setting::get('vat_enabled', '0') === '1';
        $this->vat_rate    = (float) Setting::get('vat_rate', '0');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'currency_symbol'   => 'required|string|max:10',
            'currency_position' => 'required|in:before,after',
            'decimal_places'    => 'required|integer|min:0|max:4',
            'date_format'       => 'required|string',
            'time_format'       => 'required|in:12,24',
            'timezone'          => 'required|string',
            'language'          => 'required|string',
            'items_per_page'    => 'required|integer|min:5|max:100',
            'low_stock_warning' => 'boolean',
            'vat_enabled'       => 'boolean',
            'vat_rate'          => 'required|numeric|min:0|max:100',
        ]);

        Setting::set('currency_symbol', $this->currency_symbol, 'general');
        Setting::set('currency_position', $this->currency_position, 'general');
        Setting::set('decimal_places', (string) $this->decimal_places, 'general');

        Setting::set('date_format', $this->date_format, 'general');
        Setting::set('time_format', $this->time_format, 'general');
        Setting::set('timezone', $this->timezone, 'general');

        Setting::set('language', $this->language, 'general');
        Setting::set('items_per_page', (string) $this->items_per_page, 'general');

        Setting::set('low_stock_warning', $this->low_stock_warning ? '1' : '0', 'general');

        Setting::set('vat_enabled', $this->vat_enabled ? '1' : '0', 'general');
        Setting::set('vat_rate', (string) $this->vat_rate, 'general');

        ActivityLogger::log('setting_update', 'Updated General Settings.');
        Flux::toast(variant: 'success', text: __('General settings saved.'));
    }
}; ?>

<div class="flex flex-col gap-4 sm:gap-6">

    {{-- ── Page Header ── --}}
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/40">
                <flux:icon.cog-6-tooth class="size-4 text-violet-600 dark:text-violet-400" />
            </span>
            {{ __('General Settings') }}
        </h1>
        <p class="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 ml-9">
            {{ __('Configure system-wide defaults for currency, date, display, and tax') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="flex flex-col gap-4 sm:gap-6">

        {{-- ── Currency Settings ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-emerald-100 dark:bg-emerald-950/40">
                        <flux:icon.currency-dollar class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                    </span>
                    {{ __('Currency Settings') }}
                </h2>
            </div>
            {{-- Stack on mobile, 3 cols on md+ --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Currency Symbol') }}</label>
                    <flux:input wire:model="currency_symbol" placeholder="Rs" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Currency Position') }}</label>
                    <flux:select wire:model="currency_position">
                        <flux:select.option value="before">{{ __('Before Amount (Rs 100)') }}</flux:select.option>
                        <flux:select.option value="after">{{ __('After Amount (100 Rs)') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="sm:col-span-2 md:col-span-1">
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Decimal Places') }}</label>
                    <flux:input wire:model="decimal_places" type="number" min="0" max="4" />
                </div>
            </div>
        </div>

        {{-- ── Date & Time Settings ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-blue-100 dark:bg-blue-950/40">
                        <flux:icon.calendar-days class="size-3.5 text-blue-600 dark:text-blue-400" />
                    </span>
                    {{ __('Date & Time Settings') }}
                </h2>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Date Format') }}</label>
                    <flux:select wire:model="date_format">
                        <flux:select.option value="Y-m-d">YYYY-MM-DD ({{ now()->format('Y-m-d') }})</flux:select.option>
                        <flux:select.option value="d/m/Y">DD/MM/YYYY ({{ now()->format('d/m/Y') }})</flux:select.option>
                        <flux:select.option value="m/d/Y">MM/DD/YYYY ({{ now()->format('m/d/Y') }})</flux:select.option>
                        <flux:select.option value="d-m-Y">DD-MM-YYYY ({{ now()->format('d-m-Y') }})</flux:select.option>
                        <flux:select.option value="d M Y">DD Mon YYYY ({{ now()->format('d M Y') }})</flux:select.option>
                    </flux:select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Time Format') }}</label>
                    <flux:select wire:model="time_format">
                        <flux:select.option value="12">{{ __('12 Hour (02:30 PM)') }}</flux:select.option>
                        <flux:select.option value="24">{{ __('24 Hour (14:30)') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="sm:col-span-2 md:col-span-1">
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Timezone') }}</label>
                    <flux:select wire:model="timezone">
                        <flux:select.option value="Asia/Colombo">Asia/Colombo (Sri Lanka)</flux:select.option>
                        <flux:select.option value="Asia/Kolkata">Asia/Kolkata (India)</flux:select.option>
                        <flux:select.option value="Asia/Dubai">Asia/Dubai (UAE)</flux:select.option>
                        <flux:select.option value="Asia/Singapore">Asia/Singapore</flux:select.option>
                        <flux:select.option value="Asia/Kuala_Lumpur">Asia/Kuala_Lumpur</flux:select.option>
                        <flux:select.option value="Europe/London">Europe/London</flux:select.option>
                        <flux:select.option value="America/New_York">America/New_York</flux:select.option>
                        <flux:select.option value="UTC">UTC</flux:select.option>
                    </flux:select>
                </div>
            </div>
        </div>

        {{-- ── Display Settings ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-purple-100 dark:bg-purple-950/40">
                        <flux:icon.computer-desktop class="size-3.5 text-purple-600 dark:text-purple-400" />
                    </span>
                    {{ __('Display Settings') }}
                </h2>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Language') }}</label>
                    <flux:select wire:model="language">
                        <flux:select.option value="en">English</flux:select.option>
                        <flux:select.option value="si">Sinhala (සිංහල)</flux:select.option>
                        <flux:select.option value="ta">Tamil (தமிழ்)</flux:select.option>
                    </flux:select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Items Per Page') }}</label>
                    <flux:input wire:model="items_per_page" type="number" min="5" max="100" />
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('Number of items to display in lists') }}</p>
                </div>
            </div>
        </div>

        {{-- ── Stock Management ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-orange-100 dark:bg-orange-950/40">
                        <flux:icon.cube class="size-3.5 text-orange-600 dark:text-orange-400" />
                    </span>
                    {{ __('Stock Management') }}
                </h2>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Low Stock Warning') }}</p>
                    <p class="text-xs text-zinc-400 mt-0.5 leading-snug">{{ __('Show warnings when products are running low') }}</p>
                </div>
                <flux:switch wire:model="low_stock_warning" class="shrink-0" />
            </div>
        </div>

        {{-- ── VAT Settings ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-green-100 dark:bg-green-950/40">
                        <flux:icon.receipt-percent class="size-3.5 text-green-600 dark:text-green-400" />
                    </span>
                    {{ __('VAT Settings') }}
                </h2>
            </div>
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-4">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Enable VAT') }}</p>
                    <flux:switch wire:model="vat_enabled" class="shrink-0" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('VAT Rate (%)') }}</label>
                    <flux:input wire:model="vat_rate" type="number" min="0" max="100" step="0.01" />
                    <p class="text-[11px] text-zinc-400 mt-1 leading-snug">
                        {{ __('This percentage will be used across the system. If VAT is disabled, no VAT will be applied.') }}
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Save ── --}}
        <div class="pb-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save General Settings') }}
            </flux:button>
        </div>

    </form>
</div>
