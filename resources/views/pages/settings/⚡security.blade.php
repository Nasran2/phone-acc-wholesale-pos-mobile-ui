<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Security')" :subheading="__('Manage your password, two-factor authentication, and passkeys')">
        
        {{-- ── Section: Update Password ── --}}
        <div class="space-y-4">
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800/80">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/40">
                    <flux:icon.key class="size-4 text-violet-600 dark:text-violet-400" />
                </span>
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Update Password') }}</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Ensure your account is using a long, random password to stay secure') }}</p>
                </div>
            </div>

            <form method="POST" wire:submit="updatePassword" class="mt-4 flex flex-col gap-4">
                {{-- Current Password --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.lock-closed class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Current password') }} <span class="text-rose-500">*</span>
                    </label>
                    <flux:input
                        wire:model="current_password"
                        type="password"
                        placeholder="{{ __('Enter current password') }}"
                        required
                        autocomplete="current-password"
                        viewable
                    />
                </div>

                {{-- New Password --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.key class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('New password') }} <span class="text-rose-500">*</span>
                    </label>
                    <flux:input
                        wire:model="password"
                        type="password"
                        placeholder="{{ __('Enter new password') }}"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                    />
                </div>

                {{-- Confirm Password --}}
                <div>
                    <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                        <flux:icon.check-circle class="size-3.5 text-zinc-400 shrink-0" />
                        {{ __('Confirm password') }} <span class="text-rose-500">*</span>
                    </label>
                    <flux:input
                        wire:model="password_confirmation"
                        type="password"
                        placeholder="{{ __('Confirm new password') }}"
                        required
                        autocomplete="new-password"
                        passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                        viewable
                    />
                </div>

                <div class="pt-2 flex justify-start">
                    <flux:button variant="primary" type="submit" class="w-full sm:w-auto" icon="document-arrow-down" data-test="update-password-button">
                        {{ __('Update Password') }}
                    </flux:button>
                </div>
            </form>
        </div>

        {{-- ── Section: Two-Factor Authentication ── --}}
        @if ($canManageTwoFactor)
            <div class="mt-10 pt-10 border-t border-zinc-100 dark:border-zinc-800/80 space-y-4">
                <div class="flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800/80">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-950/40">
                        <flux:icon.shield-check class="size-4 text-blue-600 dark:text-blue-400" />
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Two-Factor Authentication') }}</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Add an extra layer of security to your account') }}</p>
                    </div>
                </div>

                <div class="flex flex-col w-full mx-auto space-y-4 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="p-4 rounded-xl border border-emerald-100 dark:border-emerald-950/30 bg-emerald-50/30 dark:bg-emerald-950/5 flex items-start gap-3">
                            <flux:icon.check-circle class="size-5 text-emerald-600 dark:text-emerald-450 shrink-0 mt-0.5" />
                            <div class="flex-1 space-y-2">
                                <p class="text-xs font-bold text-emerald-800 dark:text-emerald-450">
                                    {{ __('Two-factor authentication is active and securing your account.') }}
                                </p>
                                <p class="text-xs text-emerald-700/85 dark:text-emerald-400/75 leading-relaxed">
                                    {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-start">
                            <flux:button
                                variant="danger"
                                size="sm"
                                icon="shield-exclamation"
                                wire:click="disable"
                                class="shadow-sm"
                            >
                                {{ __('Disable 2FA') }}
                            </flux:button>
                        </div>

                        <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                    @else
                        <div class="p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-start gap-3">
                            <flux:icon.information-circle class="size-5 text-zinc-400 dark:text-zinc-500 shrink-0 mt-0.5" />
                            <div class="flex-1">
                                <p class="text-xs text-zinc-650 dark:text-zinc-400 leading-relaxed">
                                    {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-start">
                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    size="sm"
                                    icon="shield-check"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                    class="shadow-sm"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ── Section: Passkeys ── --}}
        @if ($canManagePasskeys)
            <div class="mt-10 pt-10 border-t border-zinc-100 dark:border-zinc-800/80 space-y-4">
                <div class="flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800/80">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-950/40">
                        <flux:icon.key class="size-4 text-indigo-600 dark:text-indigo-400" />
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Passkeys') }}</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Manage your passkeys for secure, passwordless sign-in') }}</p>
                    </div>
                </div>

                <div class="flex flex-col w-full mx-auto space-y-4 text-sm" wire:cloak>
                    <div class="border rounded-2xl border-zinc-200 dark:border-zinc-800 overflow-hidden bg-white dark:bg-zinc-900/50 shadow-sm">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-150 dark:border-zinc-800' : '' }}">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-750">
                                        <flux:icon.key class="size-4.5 text-zinc-500 dark:text-zinc-400" />
                                    </div>
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-xs font-bold text-zinc-850 dark:text-zinc-200">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm" color="zinc" inset class="text-[10px] font-bold">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-zinc-500 dark:text-zinc-450 text-[10px] flex items-center gap-1.5">
                                            <span>{{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}</span>
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="text-zinc-300 dark:text-zinc-700">•</span>
                                                <span>{{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800/80 border border-zinc-100 dark:border-zinc-750">
                                    <flux:icon.key class="size-6 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ __('No passkeys registered yet') }}</p>
                                <flux:text class="mt-1 text-[11px]">{{ __('Add a passkey to log in securely using Touch ID, Face ID, or your device passcode.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <div class="pt-2">
                        <x-passkey-registration />
                    </div>
                </div>
            </div>
        @endif
    </x-pages::settings.layout>

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
