<?php

use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice Settings')] class extends Component
{
    public string $invoice_prefix      = 'INV-';
    public string $invoice_paper_size  = 'A4';
    public bool   $invoice_show_logo   = true;
    public string $invoice_footer_text = 'Thank you for your business!';
    public string $invoice_terms       = '';

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->invoice_prefix     = Setting::get('invoice_prefix', 'INV-');
        $this->invoice_paper_size = Setting::get('invoice_paper_size', 'A4');
        $this->invoice_show_logo  = Setting::get('invoice_show_logo', '1') === '1';
        $this->invoice_footer_text = Setting::get('invoice_footer_note', 'Thank you for your business!');
        $this->invoice_terms      = Setting::get('invoice_terms', '');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'invoice_prefix'     => 'required|string|max:20',
            'invoice_paper_size' => 'required|string',
            'invoice_show_logo'  => 'boolean',
            'invoice_footer_text' => 'nullable|string|max:500',
            'invoice_terms'      => 'nullable|string|max:2000',
        ]);

        Setting::set('invoice_prefix', $this->invoice_prefix, 'invoice');
        Setting::set('invoice_paper_size', $this->invoice_paper_size, 'invoice');
        Setting::set('invoice_show_logo', $this->invoice_show_logo ? '1' : '0', 'invoice');
        Setting::set('invoice_footer_note', $this->invoice_footer_text, 'invoice');
        Setting::set('invoice_terms', $this->invoice_terms, 'invoice');

        ActivityLogger::log('setting_update', 'Updated Invoice Settings.');
        Flux::toast(variant: 'success', text: __('Invoice settings saved.'));
    }
}; ?>

<div class="flex flex-col gap-4 sm:gap-6">

    {{-- ── Page Header ── --}}
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-green-100 dark:bg-green-950/40">
                <flux:icon.document-text class="size-4 text-green-600 dark:text-green-400" />
            </span>
            {{ __('Invoice Settings') }}
        </h1>
        <p class="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 ml-9">
            {{ __('Configure invoice formatting, display options, and terms') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="flex flex-col gap-4 sm:gap-6">

        {{-- ── Invoice Format ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-blue-100 dark:bg-blue-950/40">
                        <flux:icon.document class="size-3.5 text-blue-600 dark:text-blue-400" />
                    </span>
                    {{ __('Invoice Format') }}
                </h2>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Invoice Prefix') }}</label>
                    <flux:input wire:model="invoice_prefix" placeholder="INV-" />
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('Example: INV-0001, INV-0002') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Paper Size') }}</label>
                    <flux:select wire:model="invoice_paper_size">
                        <flux:select.option value="A4">A4 (210 × 297 mm)</flux:select.option>
                        <flux:select.option value="A5">A5 (148 × 210 mm)</flux:select.option>
                        <flux:select.option value="letter">Letter (215.9 × 279.4 mm)</flux:select.option>
                        <flux:select.option value="thermal_80mm">Thermal 80mm</flux:select.option>
                        <flux:select.option value="thermal_58mm">Thermal 58mm</flux:select.option>
                    </flux:select>
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('Select receipt paper size for printing') }}</p>
                </div>
            </div>
        </div>

        {{-- ── Display Options ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-purple-100 dark:bg-purple-950/40">
                        <flux:icon.eye class="size-3.5 text-purple-600 dark:text-purple-400" />
                    </span>
                    {{ __('Display Options') }}
                </h2>
            </div>
            <div class="flex flex-col gap-4">
                {{-- Show Logo Toggle --}}
                <div class="flex items-center justify-between gap-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Show Business Logo') }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5 leading-snug">{{ __('Display your business logo on invoices') }}</p>
                    </div>
                    <flux:switch wire:model="invoice_show_logo" class="shrink-0" />
                </div>

                {{-- Footer Text --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Footer Text') }}</label>
                    <flux:textarea wire:model="invoice_footer_text" rows="3" placeholder="Thank you for your business!" />
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('Appears at the bottom of invoices') }}</p>
                </div>
            </div>
        </div>

        {{-- ── Terms & Conditions ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-orange-100 dark:bg-orange-950/40">
                        <flux:icon.clipboard-document-list class="size-3.5 text-orange-600 dark:text-orange-400" />
                    </span>
                    {{ __('Terms & Conditions') }}
                </h2>
            </div>
            <div>
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Invoice Terms') }}</label>
                <flux:textarea wire:model="invoice_terms" rows="5" placeholder="Enter your invoice terms and conditions..." />
                <p class="text-[11px] text-zinc-400 mt-1">{{ __('Standard terms printed on all invoices') }}</p>
            </div>
        </div>

        {{-- ── Paper Size Guide Info ── --}}
        <div class="rounded-xl border border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/20 p-4">
            <div class="flex items-start gap-3">
                <flux:icon.information-circle class="size-5 text-blue-500 dark:text-blue-400 mt-0.5 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-blue-900 dark:text-blue-300">{{ __('Paper Size Guide') }}</p>
                    <ul class="mt-1.5 space-y-1 text-xs text-blue-700 dark:text-blue-400">
                        <li>• <strong>A4:</strong> {{ __('Standard office paper (best for detailed invoices)') }}</li>
                        <li>• <strong>A5:</strong> {{ __('Half-size paper for compact receipts') }}</li>
                        <li>• <strong>Letter:</strong> {{ __('US standard paper size') }}</li>
                        <li>• <strong>Thermal 80mm:</strong> {{ __('Standard POS thermal printer roll') }}</li>
                        <li>• <strong>Thermal 58mm:</strong> {{ __('Compact thermal printer roll') }}</li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- ── Save ── --}}
        <div class="pb-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save Invoice Settings') }}
            </flux:button>
        </div>

    </form>
</div>
