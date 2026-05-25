<?php

use Database\Seeders\SampleProductsSeeder;
use Livewire\Livewire;

test('product catalog renders responsive cards and filters independently', function () {
    $this->seed(SampleProductsSeeder::class);

    Livewire::test('pos.product-catalog')
        ->assertSee('grid-cols-2', false)
        ->assertSee('sm:grid-cols-3', false)
        ->assertSee('AirPods Pro Clear Case')
        ->set('search', 'AirPods')
        ->assertSee('AirPods Pro Clear Case')
        ->assertDontSee('Magnetic Car Holder');
});
