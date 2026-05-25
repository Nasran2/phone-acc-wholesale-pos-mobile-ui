<?php

namespace App\Livewire\Forms;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Rules\Unique;
use Livewire\Form;
use Livewire\WithFileUploads;

class ProductForm extends Form
{
    use WithFileUploads;

    public ?Product $product = null;

    public string $name = '';

    public ?int $category_id = null;

    public ?int $brand_id = null;

    public ?int $unit_id = null;

    public string $sku = '';

    public ?string $barcode = null;

    public ?string $compatible_models = null;

    public $cost_price = 0.0;

    public $selling_price = 0.0;

    public $wholesale_price = null;

    public int $stock_quantity = 0;

    public int $minimum_stock = 0;

    public bool $warranty_enabled = false;

    public ?int $warranty_period_days = null;

    public bool $is_active = true;

    public ?string $image_path = null;

    public $image;

    public function setProduct(Product $product): void
    {
        $this->product = $product;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->brand_id = $product->brand_id;
        $this->unit_id = $product->unit_id;
        $this->sku = $product->sku;
        $this->barcode = $product->barcode;
        $this->compatible_models = $product->compatible_models;
        $this->cost_price = (float) $product->cost_price;
        $this->selling_price = (float) $product->selling_price;
        $this->wholesale_price = $product->wholesale_price !== null
            ? (float) $product->wholesale_price
            : null;
        $this->stock_quantity = (int) $product->stock_quantity;
        $this->minimum_stock = (int) $product->minimum_stock;
        $this->warranty_enabled = (bool) $product->warranty_enabled;
        $this->warranty_period_days = $product->warranty_period_days;
        $this->is_active = (bool) $product->is_active;
        $this->image_path = $product->image_path;
    }

    /**
     * @return array<string, array<int, string|Unique|RequiredIf>>
     */
    public function rules(): array
    {
        $productId = $this->product?->id;

        return [
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Product::class, 'sku')->ignore($productId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique(Product::class, 'barcode')->ignore($productId),
            ],
            'compatible_models' => ['nullable', 'string', 'max:255'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'warranty_enabled' => ['boolean'],
            'warranty_period_days' => [
                Rule::requiredIf(fn () => $this->warranty_enabled),
                'nullable',
                'integer',
                'min:1',
            ],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function store(): Product
    {
        $this->fillAutoFields();
        $this->validate();

        $imagePath = $this->storeImage();

        return Product::create($this->payload($imagePath));
    }

    public function update(): Product
    {
        $this->fillAutoFields();
        $this->validate();

        $imagePath = $this->storeImage($this->image_path);

        $this->product->update($this->payload($imagePath));

        return $this->product->refresh();
    }

    private function fillAutoFields(): void
    {
        if (empty($this->sku)) {
            $this->sku = strtoupper(uniqid('SKU-'));
        }
        if (empty($this->barcode)) {
            $this->barcode = $this->sku;
        }
    }

    private function payload(?string $imagePath): array
    {
        return [
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'unit_id' => $this->unit_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode ?: null,
            'image_path' => $imagePath,
            'compatible_models' => $this->compatible_models ?: null,
            'cost_price' => (float) $this->cost_price,
            'selling_price' => (float) $this->selling_price,
            'wholesale_price' => ($this->wholesale_price !== null && $this->wholesale_price !== '') ? (float) $this->wholesale_price : null,
            'stock_quantity' => $this->stock_quantity,
            'minimum_stock' => $this->minimum_stock,
            'warranty_enabled' => $this->warranty_enabled,
            'warranty_period_days' => $this->warranty_enabled ? $this->warranty_period_days : null,
            'is_active' => $this->is_active,
        ];
    }

    private function storeImage(?string $existingPath = null): ?string
    {
        if (! $this->image) {
            return $existingPath;
        }

        $path = $this->image->store('products', 'public');

        if ($existingPath) {
            Storage::disk('public')->delete($existingPath);
        }

        return $path;
    }
}
