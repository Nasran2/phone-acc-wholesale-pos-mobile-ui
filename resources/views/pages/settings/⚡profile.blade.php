<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $username = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->username = Auth::user()->username ?? '';
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name, username and email address')">
        <form wire:submit="updateProfileInformation" class="my-3 w-full flex flex-col gap-5">
            {{-- Name Field --}}
            <div>
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                    <flux:icon.user class="size-3.5 text-zinc-400 shrink-0" />
                    {{ __('Name') }} <span class="text-rose-500">*</span>
                </label>
                <flux:input wire:model="name" type="text" placeholder="{{ __('Enter your name') }}" required autofocus autocomplete="name" />
            </div>

            {{-- Username Field --}}
            <div>
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                    <flux:icon.identification class="size-3.5 text-zinc-400 shrink-0" />
                    {{ __('Username') }} <span class="text-rose-500">*</span>
                </label>
                <flux:input wire:model="username" type="text" placeholder="{{ __('Enter your username') }}" required autocomplete="username" />
            </div>

            {{-- Email Field --}}
            <div>
                <label class="block text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-1.5 flex items-center gap-1.5">
                    <flux:icon.envelope class="size-3.5 text-zinc-400 shrink-0" />
                    {{ __('Email') }} <span class="text-rose-500">*</span>
                </label>
                <flux:input wire:model="email" type="email" placeholder="{{ __('Enter your email') }}" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div class="mt-4 p-4 rounded-xl border border-amber-100 dark:border-amber-900/30 bg-amber-50/50 dark:bg-amber-950/10 flex items-start gap-3">
                        <flux:icon.exclamation-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div class="flex-1 space-y-1">
                            <p class="text-xs font-bold text-amber-800 dark:text-amber-300">
                                {{ __('Your email address is unverified.') }}
                            </p>
                            <p class="text-xs text-amber-700 dark:text-amber-400">
                                <flux:link class="cursor-pointer text-xs font-semibold underline decoration-amber-500/40 hover:text-amber-900 dark:hover:text-amber-200" wire:click.prevent="resendVerificationNotification">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:link>
                            </p>
                            @if (session('status') === 'verification-link-sent')
                                <p class="text-xs font-bold text-green-600 dark:text-green-400 mt-2">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Save Button --}}
            <div class="pt-2 flex justify-start">
                <flux:button variant="primary" type="submit" class="w-full sm:w-auto" icon="document-arrow-down" data-test="update-profile-button">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <div class="border-t border-zinc-100 dark:border-zinc-800/80 my-8 pt-2">
                <livewire:pages::settings.delete-user-form />
            </div>
        @endif
    </x-pages::settings.layout>
</section>

