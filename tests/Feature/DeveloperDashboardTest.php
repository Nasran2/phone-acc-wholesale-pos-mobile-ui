<?php

use App\Models\User;
use Livewire\Livewire;

test('developer dashboard redirects guests to developer login', function () {
    $this->get(route('developer.dashboard'))
        ->assertRedirect(route('developer.login'));
});

test('developer login page renders developer credentials form', function () {
    $this->get(route('developer.login'))
        ->assertOk()
        ->assertSee('Developer access')
        ->assertSee('Open Developer Dashboard');
});

test('developer can sign in with configured credentials', function () {
    Livewire::test('pages::developer.login')
        ->set('username', 'developer')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('developer.dashboard'));

    expect(session()->get((string) config('developer.session_key')))->toBeTrue();
});

test('developer login rejects wrong credentials', function () {
    Livewire::test('pages::developer.login')
        ->set('username', 'developer')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('username');
});

test('developer dashboard renders when developer session is active', function () {
    $this->withSession([(string) config('developer.session_key') => true])
        ->get(route('developer.dashboard'))
        ->assertOk()
        ->assertSee('Developer Dashboard')
        ->assertSee('Artisan Command Presets')
        ->assertSee('Maintenance Mode On');
});

test('developer dashboard runs an allowed artisan preset and records output', function () {
    $this->withSession([(string) config('developer.session_key') => true]);

    Livewire::test('pages::developer.dashboard')
        ->call('runCommand', 'about')
        ->assertSet('results.0.label', 'About')
        ->assertSet('results.0.command', 'php artisan about')
        ->assertSet('results.0.successful', true);
});

test('authenticated users can see the developer option', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Developer');
});
