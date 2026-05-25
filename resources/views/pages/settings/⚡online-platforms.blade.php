<?php

use App\Models\Setting;
use App\Services\ActivityLogger;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Online Platforms')] class extends Component
{
    public string $website_url          = '';
    public bool   $online_store_enabled = false;

    public string $facebook_url      = '';
    public string $instagram_url     = '';
    public string $whatsapp_number   = '';
    public string $tiktok_url        = '';
    public string $youtube_url       = '';
    public string $twitter_url       = '';

    public string $google_maps_url      = '';
    public string $google_business_name = '';

    public function mount(): void
    {
        if (! auth()->user()->isAdmin() && auth()->user()->role !== 'super_admin') {
            abort(403, 'Unauthorized access.');
        }

        $this->website_url          = Setting::get('website_url', '');
        $this->online_store_enabled = Setting::get('online_store_enabled', '0') === '1';

        $this->facebook_url    = Setting::get('facebook_url', '');
        $this->instagram_url   = Setting::get('instagram_url', '');
        $this->whatsapp_number = Setting::get('whatsapp_number', '');
        $this->tiktok_url      = Setting::get('tiktok_url', '');
        $this->youtube_url     = Setting::get('youtube_url', '');
        $this->twitter_url     = Setting::get('twitter_url', '');

        $this->google_maps_url      = Setting::get('google_maps_url', '');
        $this->google_business_name = Setting::get('google_business_name', '');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'website_url'          => 'nullable|url|max:300',
            'online_store_enabled' => 'boolean',
            'facebook_url'         => 'nullable|url|max:300',
            'instagram_url'        => 'nullable|url|max:300',
            'whatsapp_number'      => 'nullable|string|max:20',
            'tiktok_url'           => 'nullable|url|max:300',
            'youtube_url'          => 'nullable|url|max:300',
            'twitter_url'          => 'nullable|url|max:300',
            'google_maps_url'      => 'nullable|url|max:500',
            'google_business_name' => 'nullable|string|max:200',
        ]);

        Setting::set('website_url', $this->website_url, 'platforms');
        Setting::set('online_store_enabled', $this->online_store_enabled ? '1' : '0', 'platforms');

        Setting::set('facebook_url', $this->facebook_url, 'platforms');
        Setting::set('instagram_url', $this->instagram_url, 'platforms');
        Setting::set('whatsapp_number', $this->whatsapp_number, 'platforms');
        Setting::set('tiktok_url', $this->tiktok_url, 'platforms');
        Setting::set('youtube_url', $this->youtube_url, 'platforms');
        Setting::set('twitter_url', $this->twitter_url, 'platforms');

        Setting::set('google_maps_url', $this->google_maps_url, 'platforms');
        Setting::set('google_business_name', $this->google_business_name, 'platforms');

        ActivityLogger::log('setting_update', 'Updated Online Platform Settings.');
        Flux::toast(variant: 'success', text: __('Online platform settings saved.'));
    }
}; ?>

<div class="flex flex-col gap-4 sm:gap-6">

    {{-- ── Page Header ── --}}
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-100 dark:bg-sky-950/40">
                <flux:icon.squares-plus class="size-4 text-sky-600 dark:text-sky-400" />
            </span>
            {{ __('Online Platforms') }}
        </h1>
        <p class="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 ml-9">
            {{ __('Connect your business website, social media, and online presence') }}
        </p>
    </div>

    <form wire:submit="saveSettings" class="flex flex-col gap-4 sm:gap-6">

        {{-- ── Website / Online Store ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-blue-100 dark:bg-blue-950/40">
                        <flux:icon.globe-alt class="size-3.5 text-blue-600 dark:text-blue-400" />
                    </span>
                    {{ __('Website & Online Store') }}
                </h2>
            </div>
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.globe-alt class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Website URL') }}
                    </label>
                    <flux:input wire:model="website_url" type="url" placeholder="https://yourbusiness.com" />
                </div>
                <div class="flex items-center justify-between gap-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-700 p-3 sm:p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Enable Online Store') }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5 leading-snug">{{ __('Show online storefront link on customer receipts and notifications') }}</p>
                    </div>
                    <flux:switch wire:model="online_store_enabled" class="shrink-0" />
                </div>
            </div>
        </div>

        {{-- ── Social Media Links ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-pink-100 dark:bg-pink-950/40">
                        <flux:icon.heart class="size-3.5 text-pink-600 dark:text-pink-400" />
                    </span>
                    {{ __('Social Media Links') }}
                </h2>
                <p class="text-xs text-zinc-400 mt-1 ml-7">{{ __('Add your social media profiles to appear on receipts') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                {{-- Facebook --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <svg class="size-3.5 text-blue-600 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                        Facebook
                    </label>
                    <flux:input wire:model="facebook_url" type="url" placeholder="https://facebook.com/yourbusiness" />
                </div>

                {{-- Instagram --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <svg class="size-3.5 text-pink-500 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                        </svg>
                        Instagram
                    </label>
                    <flux:input wire:model="instagram_url" type="url" placeholder="https://instagram.com/yourbusiness" />
                </div>

                {{-- WhatsApp --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <svg class="size-3.5 text-green-500 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                        WhatsApp
                    </label>
                    <flux:input wire:model="whatsapp_number" placeholder="+94712345678" />
                    <p class="text-[11px] text-zinc-400 mt-1">{{ __('Include country code') }}</p>
                </div>

                {{-- TikTok --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">TikTok</label>
                    <flux:input wire:model="tiktok_url" type="url" placeholder="https://tiktok.com/@yourbusiness" />
                </div>

                {{-- YouTube --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">YouTube</label>
                    <flux:input wire:model="youtube_url" type="url" placeholder="https://youtube.com/c/yourchannel" />
                </div>

                {{-- Twitter / X --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">Twitter / X</label>
                    <flux:input wire:model="twitter_url" type="url" placeholder="https://twitter.com/yourbusiness" />
                </div>

            </div>
        </div>

        {{-- ── Google Business ── --}}
        <div class="app-card p-4 sm:p-6">
            <div class="border-b border-zinc-100 dark:border-zinc-800 pb-3 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-red-100 dark:bg-red-950/40">
                        <flux:icon.map-pin class="size-3.5 text-red-600 dark:text-red-400" />
                    </span>
                    {{ __('Google Business') }}
                </h2>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Google Business Name') }}</label>
                    <flux:input wire:model="google_business_name" placeholder="Your Business Name on Google" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5">{{ __('Google Maps URL') }}</label>
                    <flux:input wire:model="google_maps_url" type="url" placeholder="https://maps.google.com/..." />
                </div>
            </div>
        </div>

        {{-- ── Save ── --}}
        <div class="pb-2">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto" icon="document-arrow-down">
                {{ __('Save Online Platforms') }}
            </flux:button>
        </div>

    </form>
</div>
