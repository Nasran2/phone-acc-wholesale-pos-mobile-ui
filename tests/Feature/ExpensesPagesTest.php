<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Livewire\Livewire;

test('authenticated users can visit expenses pages', function (string $routeName, string $heading) {
    $this->actingAs(User::factory()->create());

    $this->get(route($routeName))
        ->assertOk()
        ->assertSee($heading);
})->with([
    'expenses list' => ['expenses.index', 'Expense List'],
    'add expense' => ['expenses.create', 'Add Expense'],
    'expense categories' => ['expenses.categories', 'Expense Categories'],
]);

test('expense list filters by category and payment method', function () {
    $user = User::factory()->create();

    ExpenseCategory::query()->firstOrCreate(['name' => 'Test Utilities']);
    ExpenseCategory::query()->firstOrCreate(['name' => 'Test Rent']);

    Expense::query()->create([
        'category' => 'Test Utilities',
        'amount' => 100,
        'date' => today()->toDateString(),
        'payment_method' => 'cash',
        'reference' => 'EXP-UTIL-1',
        'notes' => 'Utility payment',
    ]);

    Expense::query()->create([
        'category' => 'Test Rent',
        'amount' => 200,
        'date' => today()->toDateString(),
        'payment_method' => 'card',
        'reference' => 'EXP-RENT-1',
        'notes' => 'Rent payment',
    ]);

    Livewire::actingAs($user)
        ->test('pages::expenses.index')
        ->assertSee('EXP-UTIL-1')
        ->assertSee('EXP-RENT-1')
        ->set('category', 'Test Utilities')
        ->assertSee('EXP-UTIL-1')
        ->assertDontSee('EXP-RENT-1')
        ->set('paymentMethod', 'cash')
        ->assertSee('EXP-UTIL-1');
});
