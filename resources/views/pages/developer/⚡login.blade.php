<?php

use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth.card')]
#[Title('Developer login')] class extends Component
{
    public string $username = '';

    public string $password = '';

    public function login(): void
    {
        $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $validUsername = hash_equals((string) config('developer.username'), $this->username);
        $validPassword = hash_equals((string) config('developer.password'), $this->password);

        if (! $validUsername || ! $validPassword) {
            $this->addError('username', __('The developer credentials are incorrect.'));

            return;
        }

        session()->regenerate();
        session()->put((string) config('developer.session_key'), true);

        Flux::toast(variant: 'success', text: __('Developer access granted.'));

        $this->redirect(route('developer.dashboard'), navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Developer access')"
        :description="__('Sign in with the developer username and password to run server maintenance tools.')"
    />

    <form wire:submit="login" class="flex flex-col gap-5">
        <flux:input
            wire:model="username"
            :label="__('Username')"
            type="text"
            required
            autofocus
            autocomplete="username"
            placeholder="developer"
        />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="current-password"
            placeholder="password"
            viewable
        />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Open Developer Dashboard') }}
        </flux:button>
    </form>
</div>
