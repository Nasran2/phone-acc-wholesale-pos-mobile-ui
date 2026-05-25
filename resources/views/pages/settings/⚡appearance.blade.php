<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Customize the look and feel of your application')">
        <div class="space-y-6" x-data>
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800/80">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-950/40">
                    <flux:icon.swatch class="size-4 text-emerald-600 dark:text-emerald-450" />
                </span>
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Theme Selection') }}</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Choose a theme that fits your workstation') }}</p>
                </div>
            </div>

            <flux:radio.group variant="segmented" x-model="$flux.appearance" class="w-full">
                <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
            </flux:radio.group>

            {{-- Visual previews --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
                {{-- Light Preview Card --}}
                <button @click="$flux.appearance = 'light'" class="group relative flex flex-col gap-3 rounded-2xl border p-4 text-left transition duration-200 outline-none cursor-pointer" :class="$flux.appearance === 'light' ? 'border-violet-500 ring-2 ring-violet-500/10 bg-violet-50/10 dark:bg-violet-950/5' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700 bg-white dark:bg-zinc-900/50'">
                    <div class="w-full h-24 rounded-lg bg-zinc-50 border border-zinc-100 p-2 flex flex-col gap-1.5 overflow-hidden">
                        <div class="flex items-center gap-1.5 border-b border-zinc-100 pb-1.5">
                            <div class="size-2 rounded-full bg-zinc-300"></div>
                            <div class="h-2 w-12 rounded bg-zinc-200"></div>
                        </div>
                        <div class="flex-1 flex gap-2">
                            <div class="w-1/3 rounded bg-zinc-100 border border-zinc-200/50"></div>
                            <div class="flex-1 flex flex-col gap-1">
                                <div class="h-2 w-full rounded bg-zinc-200"></div>
                                <div class="h-2 w-2/3 rounded bg-zinc-150"></div>
                                <div class="h-2 w-1/2 rounded bg-zinc-100"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-zinc-850 dark:text-zinc-200">{{ __('Light Mode') }}</span>
                        <div class="size-4 rounded-full border flex items-center justify-center transition" :class="$flux.appearance === 'light' ? 'border-violet-500 bg-violet-500 text-white' : 'border-zinc-300 dark:border-zinc-700'">
                            <svg x-show="$flux.appearance === 'light'" class="size-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        </div>
                    </div>
                </button>

                {{-- Dark Preview Card --}}
                <button @click="$flux.appearance = 'dark'" class="group relative flex flex-col gap-3 rounded-2xl border p-4 text-left transition duration-200 outline-none cursor-pointer" :class="$flux.appearance === 'dark' ? 'border-violet-500 ring-2 ring-violet-500/10 bg-violet-50/10 dark:bg-violet-950/5' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700 bg-white dark:bg-zinc-900/50'">
                    <div class="w-full h-24 rounded-lg bg-zinc-950 border border-zinc-900 p-2 flex flex-col gap-1.5 overflow-hidden">
                        <div class="flex items-center gap-1.5 border-b border-zinc-900 pb-1.5">
                            <div class="size-2 rounded-full bg-zinc-800"></div>
                            <div class="h-2 w-12 rounded bg-zinc-850"></div>
                        </div>
                        <div class="flex-1 flex gap-2">
                            <div class="w-1/3 rounded bg-zinc-900 border border-zinc-850/50"></div>
                            <div class="flex-1 flex flex-col gap-1">
                                <div class="h-2 w-full rounded bg-zinc-850"></div>
                                <div class="h-2 w-2/3 rounded bg-zinc-900"></div>
                                <div class="h-2 w-1/2 rounded bg-zinc-950"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-zinc-850 dark:text-zinc-200">{{ __('Dark Mode') }}</span>
                        <div class="size-4 rounded-full border flex items-center justify-center transition" :class="$flux.appearance === 'dark' ? 'border-violet-500 bg-violet-500 text-white' : 'border-zinc-300 dark:border-zinc-700'">
                            <svg x-show="$flux.appearance === 'dark'" class="size-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        </div>
                    </div>
                </button>

                {{-- System Preview Card --}}
                <button @click="$flux.appearance = 'system'" class="group relative flex flex-col gap-3 rounded-2xl border p-4 text-left transition duration-200 outline-none cursor-pointer" :class="$flux.appearance === 'system' ? 'border-violet-500 ring-2 ring-violet-500/10 bg-violet-50/10 dark:bg-violet-950/5' : 'border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700 bg-white dark:bg-zinc-900/50'">
                    <div class="w-full h-24 rounded-lg bg-zinc-50 dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-900 p-2 flex flex-col gap-1.5 overflow-hidden">
                        <div class="flex items-center gap-1.5 border-b border-zinc-100 dark:border-zinc-900 pb-1.5">
                            <div class="size-2 rounded-full bg-zinc-300 dark:bg-zinc-850"></div>
                            <div class="h-2 w-12 rounded bg-zinc-200 dark:bg-zinc-850"></div>
                        </div>
                        <div class="flex-1 flex gap-2">
                            <div class="w-1/3 rounded bg-zinc-100 dark:bg-zinc-900 border border-zinc-200/50 dark:border-zinc-850/50"></div>
                            <div class="flex-1 flex flex-col gap-1">
                                <div class="h-2 w-full rounded bg-zinc-200 dark:bg-zinc-850"></div>
                                <div class="h-2 w-2/3 rounded bg-zinc-150 dark:bg-zinc-900"></div>
                                <div class="h-2 w-1/2 rounded bg-zinc-100 dark:bg-zinc-950"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-zinc-850 dark:text-zinc-200">{{ __('System Default') }}</span>
                        <div class="size-4 rounded-full border flex items-center justify-center transition" :class="$flux.appearance === 'system' ? 'border-violet-500 bg-violet-500 text-white' : 'border-zinc-300 dark:border-zinc-700'">
                            <svg x-show="$flux.appearance === 'system'" class="size-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </x-pages::settings.layout>
</section>

