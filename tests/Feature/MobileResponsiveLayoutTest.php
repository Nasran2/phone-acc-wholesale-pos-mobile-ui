<?php

use App\Models\User;

test('dashboard summary cards render as two columns on mobile', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4', false)
        ->assertSee('text-[1.05rem] font-bold leading-tight', false)
        ->assertSee('relative hidden overflow-hidden', false);
});

test('party directories use compact mobile cards and collapsible supplier registration', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('parties.customers'))
        ->assertOk()
        ->assertSee('grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-3', false)
        ->assertSee('wire:model.live.debounce.500ms="search"', false);

    $this->get(route('parties.suppliers'))
        ->assertOk()
        ->assertSee('supplierFormOpen: window.innerWidth', false)
        ->assertSee('x-show="supplierFormOpen"', false)
        ->assertSee('wire:model.live.debounce.500ms="search"', false);
});

test('business reports use two column mobile summary and result cards', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('reports.sales'))
        ->assertOk()
        ->assertSee('grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4', false)
        ->assertSee('grid grid-cols-2 gap-2 p-3 md:hidden', false)
        ->assertSee('wire:model.live.debounce.500ms="search"', false);
});
