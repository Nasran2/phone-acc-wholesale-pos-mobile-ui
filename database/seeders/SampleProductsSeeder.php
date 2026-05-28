<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class SampleProductsSeeder extends Seeder
{
    /**
     * Seed customer-facing POS sample products.
     */
    public function run(): void
    {
        $unit = Unit::query()->firstOrCreate(
            ['short_name' => 'pcs'],
            ['name' => 'Pieces', 'is_active' => true],
        );

        $products = [
            [
                'category' => 'Covers & Cases',
                'brand' => 'Spigen',
                'name' => 'AirPods Pro Clear Case',
                'sku' => 'CASE-AIRPODS-PRO',
                'barcode' => '890100000001',
                'compatible_models' => 'AirPods Pro',
                'color' => 'Clear',
                'cost_price' => 850,
                'selling_price' => 1500,
                'wholesale_price' => 1250,
                'stock_quantity' => 32,
                'minimum_stock' => 6,
            ],
            [
                'category' => 'Chargers & Adapters',
                'brand' => 'Anker',
                'name' => '20W USB-C Fast Charger',
                'sku' => 'CHR-20W-USBC',
                'barcode' => '890100000002',
                'compatible_models' => 'iPhone 12-15',
                'color' => 'White',
                'cost_price' => 1850,
                'selling_price' => 2950,
                'wholesale_price' => 2600,
                'stock_quantity' => 24,
                'minimum_stock' => 5,
            ],
            [
                'category' => 'Cables & Hubs',
                'brand' => 'Ugreen',
                'name' => 'Braided Type-C Cable 1M',
                'sku' => 'CBL-TYPEC-1M',
                'barcode' => '890100000003',
                'compatible_models' => 'Android Type-C',
                'color' => 'Black',
                'cost_price' => 420,
                'selling_price' => 950,
                'wholesale_price' => 780,
                'stock_quantity' => 64,
                'minimum_stock' => 12,
            ],
            [
                'category' => 'Tempered Glasses',
                'brand' => 'Samsung',
                'name' => 'Galaxy A55 Privacy Glass',
                'sku' => 'GLS-A55-PRIVACY',
                'barcode' => '890100000004',
                'compatible_models' => 'Samsung A55',
                'color' => 'Black Edge',
                'cost_price' => 300,
                'selling_price' => 850,
                'wholesale_price' => 650,
                'stock_quantity' => 48,
                'minimum_stock' => 10,
            ],
            [
                'category' => 'Powerbanks',
                'brand' => 'Baseus',
                'name' => '10000mAh Slim Powerbank',
                'sku' => 'PWR-10K-SLIM',
                'barcode' => '890100000005',
                'compatible_models' => 'Universal USB',
                'color' => 'Midnight',
                'cost_price' => 3200,
                'selling_price' => 5200,
                'wholesale_price' => 4700,
                'stock_quantity' => 18,
                'minimum_stock' => 4,
            ],
            [
                'category' => 'Earphones & Speakers',
                'brand' => 'Joyroom',
                'name' => 'Wireless Earbuds Mini',
                'sku' => 'EAR-MINI-WL',
                'barcode' => '890100000006',
                'compatible_models' => 'Bluetooth Devices',
                'color' => 'Pearl',
                'cost_price' => 2100,
                'selling_price' => 3900,
                'wholesale_price' => 3400,
                'stock_quantity' => 20,
                'minimum_stock' => 5,
            ],
            [
                'category' => 'Smart Watch Straps',
                'brand' => 'Apple',
                'name' => 'Silicone Watch Strap 44mm',
                'sku' => 'STRAP-44-SIL',
                'barcode' => '890100000007',
                'compatible_models' => 'Apple Watch 44mm',
                'color' => 'Forest Green',
                'cost_price' => 650,
                'selling_price' => 1450,
                'wholesale_price' => 1180,
                'stock_quantity' => 27,
                'minimum_stock' => 6,
            ],
            [
                'category' => 'Car Holders',
                'brand' => 'Remax',
                'name' => 'Magnetic Car Holder',
                'sku' => 'CAR-MAG-HOLD',
                'barcode' => '890100000008',
                'compatible_models' => 'Universal Phone',
                'color' => 'Graphite',
                'cost_price' => 720,
                'selling_price' => 1650,
                'wholesale_price' => 1380,
                'stock_quantity' => 22,
                'minimum_stock' => 5,
            ],
            [
                'category' => 'Chargers & Adapters',
                'brand' => 'LDNIO',
                'name' => 'Dual USB Travel Adapter',
                'sku' => 'CHR-LDNIO-DUAL',
                'barcode' => '890100000009',
                'compatible_models' => 'Universal USB',
                'color' => 'White',
                'cost_price' => 950,
                'selling_price' => 1850,
                'wholesale_price' => 1550,
                'stock_quantity' => 36,
                'minimum_stock' => 8,
            ],
            [
                'category' => 'Memory Cards & USBs',
                'brand' => 'Samsung',
                'name' => '64GB MicroSD Card',
                'sku' => 'MEM-MICROSD-64',
                'barcode' => '890100000010',
                'compatible_models' => 'Android Phones, Cameras',
                'color' => 'Blue',
                'cost_price' => 1350,
                'selling_price' => 2450,
                'wholesale_price' => 2150,
                'stock_quantity' => 30,
                'minimum_stock' => 6,
            ],
            [
                'category' => 'Repair Spare Parts',
                'brand' => 'Xiaomi',
                'name' => 'Redmi Charging Board',
                'sku' => 'SPARE-REDMI-CHG',
                'barcode' => '890100000011',
                'compatible_models' => 'Redmi Note Series',
                'color' => 'Green PCB',
                'cost_price' => 780,
                'selling_price' => 1750,
                'wholesale_price' => 1450,
                'stock_quantity' => 14,
                'minimum_stock' => 4,
            ],
            [
                'category' => 'Covers & Cases',
                'brand' => 'Baseus',
                'name' => 'iPhone 15 Silicone Case',
                'sku' => 'CASE-IP15-SIL',
                'barcode' => '890100000012',
                'compatible_models' => 'iPhone 15',
                'color' => 'Navy',
                'cost_price' => 650,
                'selling_price' => 1450,
                'wholesale_price' => 1200,
                'stock_quantity' => 42,
                'minimum_stock' => 10,
            ],
        ];

        foreach ($products as $product) {
            $category = Category::query()->firstOrCreate(
                ['name' => $product['category']],
                ['is_active' => true],
            );

            $brand = Brand::query()->firstOrCreate(
                ['name' => $product['brand']],
                ['is_active' => true],
            );

            Product::query()->updateOrCreate(
                ['sku' => $product['sku']],
                [
                    'category_id' => $category->id,
                    'brand_id' => $brand->id,
                    'unit_id' => $unit->id,
                    'name' => $product['name'],
                    'barcode' => $product['barcode'],
                    'image_path' => null,
                    'compatible_models' => $product['compatible_models'],
                    'color' => $product['color'],
                    'cost_price' => $product['cost_price'],
                    'selling_price' => $product['selling_price'],
                    'wholesale_price' => $product['wholesale_price'],
                    'stock_quantity' => $product['stock_quantity'],
                    'minimum_stock' => $product['minimum_stock'],
                    'warranty_enabled' => false,
                    'warranty_period_days' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
