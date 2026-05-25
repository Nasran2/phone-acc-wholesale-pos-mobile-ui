<?php

use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Business Info')] class extends Component
{
    use WithFileUploads;

    public string $business_name = '';
    public string $business_tagline = '';
    public string $business_email = '';
    public string $business_phone = '';
    public string $business_address = '';
    public $business_logo = null;
    public ?string $existing_logo = null;

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->business_name    = Setting::get('business_name', '');
        $this->business_tagline = Setting::get('business_tagline', '');
        $this->business_email   = Setting::get('business_email', '');
        $this->business_phone   = Setting::get('business_phone', '');
        $this->business_address = Setting::get('business_address', '');
        $this->existing_logo    = Setting::get('business_logo', null);
    }

    public function saveSettings(): void
    {
        $this->validate([
            'business_name'    => 'required|string|max:120',
            'business_tagline' => 'nullable|string|max:200',
            'business_email'   => 'nullable|email|max:150',
            'business_phone'   => 'nullable|string|max:30',
            'business_address' => 'nullable|string|max:500',
            'business_logo'    => 'nullable|image|max:2048',
        ]);

        if ($this->business_logo) {
            if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
                Storage::disk('public')->delete($this->existing_logo);
            }
            $path = $this->business_logo->store('logos', 'public');
            Setting::set('business_logo', $path, 'general');
            $this->existing_logo = $path;
            $this->business_logo = null;
        }

        Setting::set('business_name', $this->business_name, 'general');
        Setting::set('business_tagline', $this->business_tagline, 'general');
        Setting::set('business_email', $this->business_email, 'general');
        Setting::set('business_phone', $this->business_phone, 'general');
        Setting::set('business_address', $this->business_address, 'general');

        ActivityLogger::log('setting_update', 'Updated Business Information.');
        Flux::toast(variant: 'success', text: __('Business information saved.'));
    }

    public function removeLogo(): void
    {
        if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
            Storage::disk('public')->delete($this->existing_logo);
        }
        Setting::set('business_logo', null, 'general');
        $this->existing_logo = null;
        $this->business_logo = null;
        Flux::toast(variant: 'success', text: __('Business logo removed.'));
    }
}; ?>

<div class="flex flex-col gap-4 sm:gap-6">

    {{-- ── Page Header ── --}}
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-950/40">
                <flux:icon.building-office-2 class="size-4 text-blue-600 dark:text-blue-400" />
            </span>
            {{ __('Business Information') }}
        </h1>
        <p class="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 ml-9">
            {{ __('Manage your business identity and contact details') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="flex flex-col gap-4 sm:gap-6">

        {{-- ── Business Logo ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <flux:icon.photo class="size-4 text-zinc-400 shrink-0" />
                    {{ __('Business Logo') }}
                </h2>
                <p class="text-xs text-zinc-400 mt-0.5 ml-6">{{ __('Upload your business logo (PNG, JPG — max 2 MB)') }}</p>
            </div>

            <div class="flex flex-col gap-3">
                {{-- File picker --}}
                <div class="flex flex-wrap items-center gap-3">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition active:scale-95">
                        <flux:icon.arrow-up-tray class="size-4 text-zinc-400" />
                        {{ __('Choose File') }}
                        <input type="file" wire:model="business_logo" accept="image/*" class="sr-only" />
                    </label>
                    <span class="text-xs sm:text-sm text-zinc-400 break-all">
                        @if ($business_logo)
                            {{ $business_logo->getClientOriginalName() }}
                        @else
                            {{ __('No file chosen') }}
                        @endif
                    </span>
                </div>

                {{-- Preview / Remove --}}
                @if ($business_logo)
                    <div class="w-28 h-20 sm:w-32 sm:h-24 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <img src="{{ $business_logo->temporaryUrl() }}" alt="Logo preview" class="w-full h-full object-contain" />
                    </div>
                @elseif ($existing_logo)
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="w-28 h-20 sm:w-32 sm:h-24 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                            <img src="{{ Storage::url($existing_logo) }}" alt="Business logo" class="w-full h-full object-contain" />
                        </div>
                        <button
                            type="button"
                            wire:click="removeLogo"
                            wire:confirm="Remove the current logo?"
                            class="text-xs font-semibold text-rose-500 hover:text-rose-600 dark:text-rose-400 flex items-center gap-1 mt-1 py-1"
                        >
                            <flux:icon.trash class="size-3.5 shrink-0" />
                            {{ __('Remove Logo') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Business Details ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <flux:icon.building-office class="size-4 text-blue-600 shrink-0" />
                    {{ __('Business Details') }}
                </h2>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Business Name --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.building-office class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Business Name') }} <span class="text-rose-500">*</span>
                    </label>
                    <flux:input wire:model="business_name" placeholder="e.g. Ganga Traders" required />
                </div>

                {{-- Tagline --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.chat-bubble-bottom-center-text class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Tagline / Small Heading') }}
                    </label>
                    <flux:input wire:model="business_tagline" placeholder="e.g. Smart ERP by IntelSynQ" />
                </div>

                {{-- Email --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.envelope class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Business Email') }}
                    </label>
                    <flux:input wire:model="business_email" type="email" placeholder="contact@domain.com" />
                </div>

                {{-- Phone --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.phone class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Business Phone') }}
                    </label>
                    <flux:input wire:model="business_phone" placeholder="0712345678" />
                </div>

                {{-- Address --}}
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.map-pin class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Business Address') }}
                    </label>
                    <flux:textarea wire:model="business_address" rows="3" placeholder="Enter full business address..." />
                </div>
            </div>
        </div>

        {{-- ── Save Button ── --}}
        <div class="pb-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save Settings') }}
            </flux:button>
        </div>

    </form>
</div>
