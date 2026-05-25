<?php

use App\Livewire\Forms\ProductForm;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Edit product')] class extends Component
{
    use WithFileUploads;

    public ProductForm $form;

    public function mount(Product $product): void
    {
        $this->form->setProduct($product);
    }

    public function save(): void
    {
        $product = $this->form->update();

        Flux::toast(variant: 'success', text: __('Product updated.'));

        $this->redirect(route('products.show', $product), navigate: true);
    }

    public function quickAddCategory(string $name): void
    {
        $name = trim($name);
        if ($name) {
            $cat = Category::firstOrCreate(['name' => $name], ['is_active' => true]);
            $this->form->category_id = $cat->id;
            unset($this->categories); // clear computed cache
        }
    }

    public function quickAddBrand(string $name): void
    {
        $name = trim($name);
        if ($name) {
            $brand = Brand::firstOrCreate(['name' => $name], ['is_active' => true]);
            $this->form->brand_id = $brand->id;
            unset($this->brands);
        }
    }

    public function quickAddUnit(string $name): void
    {
        $name = trim($name);
        if ($name) {
            $unit = Unit::firstOrCreate(['name' => $name], ['is_active' => true]);
            $this->form->unit_id = $unit->id;
            unset($this->units);
        }
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function brands()
    {
        return Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function units()
    {
        return Unit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}; ?>

<div>
    <section class="app-card p-4">
        <div class="flex flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Edit</p>
            <h2 class="font-display text-xl font-semibold text-zinc-900">{{ __('Update product') }}</h2>
            <p class="text-sm text-zinc-500">{{ __('Adjust pricing, inventory, or details.') }}</p>
        </div>
    </section>

    <form wire:submit="save" class="mt-6">
        @include('pages.products.partials.form', ['submitLabel' => __('Update product')])
    </form>
</div>
