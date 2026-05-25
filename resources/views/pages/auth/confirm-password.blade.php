<x-layouts::app :title="__('Confirm password')">
    <div class="flex items-center justify-center min-h-[calc(100vh-8rem)]">
        <div class="w-full max-w-md">
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-8 space-y-6">

                <div class="space-y-1.5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 dark:bg-violet-950/40">
                            <flux:icon.shield-check class="size-6 text-violet-600 dark:text-violet-400" />
                        </div>
                        <div>
                            <h1 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Confirm your password') }}</h1>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Please confirm your password to continue.') }}</p>
                        </div>
                    </div>
                </div>

                <x-auth-session-status class="text-center" :status="session('status')" />

                {{-- Passkey confirm option --}}
                <x-passkey-verify
                    options-route="passkey.confirm-options"
                    submit-route="passkey.confirm"
                    :label="__('Confirm with passkey')"
                    :loading-label="__('Confirming...')"
                    :separator="__('Or confirm with password')"
                />

                <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <flux:input
                            name="password"
                            :label="__('Current password')"
                            type="password"
                            required
                            autofocus
                            autocomplete="current-password"
                            :placeholder="__('Enter your password')"
                            viewable
                        />
                        @error('password')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex gap-3 pt-2">
                        <flux:button variant="primary" type="submit" class="flex-1">
                            {{ __('Confirm & Continue') }}
                        </flux:button>
                        <flux:button
                            variant="outline"
                            type="button"
                            onclick="history.back()"
                        >
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts::app>
