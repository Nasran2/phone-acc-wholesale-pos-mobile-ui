<?php

use Tests\TestCase;

uses(TestCase::class);

test('developer name config is available', function () {
    config(['app.dev_name' => 'Imran POS Solutions']);

    expect(config('app.dev_name'))->toBe('Imran POS Solutions');
});
