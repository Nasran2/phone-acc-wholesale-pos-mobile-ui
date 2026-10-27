<?php

use App\Models\Product;
use App\Models\User;

test('mobile application screens render with the shared PDF and refresh bundle', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    Product::factory()->count(8)->create(['is_active' => true, 'stock_quantity' => 10]);

    foreach (['pos.index', 'sales.index', 'parties.customers', 'dashboard'] as $name) {
        $response = $this->get(route($name))->assertOk()->assertSee('images/twinsofte-logo.png', false);
        if ($previewDirectory = getenv('MOBILE_PREVIEW_DIR')) {
            if (! is_dir($previewDirectory)) {
                mkdir($previewDirectory, 0700, true);
            }
            file_put_contents($previewDirectory.'/'.$name.'.html', $response->getContent());
        }
    }
});
