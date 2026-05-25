<div class="flex flex-col md:flex-row gap-6 items-start">
    {{-- Left Navigation Sidebar --}}
    <div class="w-full md:w-[240px] shrink-0">
        <div class="app-card p-4">
            <flux:navlist aria-label="{{ __('Settings') }}">
                <flux:navlist.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item :href="route('security.edit')" icon="shield-check" wire:navigate>{{ __('Security') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.sms')" icon="chat-bubble-left-right" wire:navigate>{{ __('SMS Gateway') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            </flux:navlist>
        </div>
    </div>

    {{-- Right Content Panel --}}
    <div class="flex-1 w-full max-w-2xl">
        <div class="app-card p-5 sm:p-6">
            @if(isset($heading) || isset($subheading))
                <div class="border-b border-zinc-100 dark:border-zinc-800 pb-4 mb-5">
                    @if(isset($heading))
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $heading }}</h2>
                    @endif
                    @if(isset($subheading))
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ $subheading }}</p>
                    @endif
                </div>
            @endif

            <div>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
