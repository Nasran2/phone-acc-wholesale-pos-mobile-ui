@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <flux:text>{{ __('Passkeys are not supported in this browser.') }}</flux:text>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <flux:button
                variant="primary"
                icon="plus"
                x-on:click="showForm = true"
            >
                {{ __('Add passkey') }}
            </flux:button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 rounded-2xl border border-zinc-200 dark:border-zinc-850 bg-zinc-50/50 dark:bg-zinc-900/30 p-4 sm:p-5 shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-zinc-650 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                    <flux:icon.pencil-square class="size-3.5 text-zinc-450 shrink-0" />
                    {{ __('Passkey name') }} <span class="text-rose-500">*</span>
                </label>
                <flux:input
                    x-model="name"
                    placeholder="{{ __('e.g. MacBook Pro, iPhone') }}"
                    x-on:keydown.enter.prevent="register()"
                    x-ref="passkeyNameInput"
                    x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                />
            </div>
            <flux:text class="text-xs !mt-1">{{ __('Give this passkey a name to help you identify it later.') }}</flux:text>

            <p x-show="error" x-text="error" x-cloak class="text-xs font-semibold text-red-650 dark:text-red-400"></p>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    size="sm"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                    class="shadow-sm"
                >
                    <span x-show="!loading">{{ __('Register Passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </flux:button>
                <flux:button
                    variant="ghost"
                    size="sm"
                    x-on:click="cancel()"
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </template>
</div>

