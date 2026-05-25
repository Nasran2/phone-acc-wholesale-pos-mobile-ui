<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warrantyEnabled = $this->faker->boolean(35);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'unit_id' => Unit::factory(),
            'name' => $this->faker->words(3, true),
            'sku' => $this->faker->unique()->bothify('SKU-####??'),
            'barcode' => $this->faker->boolean(70) ? $this->faker->unique()->ean13() : null,
            'image_path' => null,
            'compatible_models' => $this->faker->boolean(60) ? $this->faker->words(2, true) : null,
            'color' => $this->faker->safeColorName(),
            'cost_price' => $this->faker->randomFloat(2, 80, 2400),
            'selling_price' => $this->faker->randomFloat(2, 120, 3600),
            'wholesale_price' => $this->faker->boolean(60)
                ? $this->faker->randomFloat(2, 100, 3200)
                : null,
            'stock_quantity' => $this->faker->numberBetween(0, 140),
            'minimum_stock' => $this->faker->numberBetween(0, 20),
            'warranty_enabled' => $warrantyEnabled,
            'warranty_period_days' => $warrantyEnabled
                ? $this->faker->randomElement([7, 30, 90, 180, 365])
                : null,
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
