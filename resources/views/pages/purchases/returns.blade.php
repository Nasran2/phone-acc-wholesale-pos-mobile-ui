<?php

use App\Models\FaultyItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Services\PurchaseReturnService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Returns')] class extends Component
{
    use WithPagination;

    public string $source = 'faulty';
    public array $selectedFaultyItems = [];
    public ?int $selectedSupplierId = null;
    public ?int $selectedPurchaseId = null;
    public array $returnItems = [];
    public array $replacementItems = [];
    public string $productSearch = '';
    public string $purchaseProductSearch = '';
    public string $returnItemSearch = '';
    public string $returnType = 'adjust_due';
    public string $balanceAction = 'adjust_due';
    public int|float|string $paidAmount = 0;
    public int|float|string $deductionAmount = 0;
    public ?string $notes = null;
    public array $exchangePayments = [];
    #[Locked]
    public array $exchangePaymentTokens = [];
    #[Locked]
    public string $operationToken;
    #[Locked]
    public ?string $lastReturnNumber = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission('add_purchase'), 403);
        $this->operationToken = (string) Str::uuid();
        if (request()->integer('purchase_id')) {
            $purchase = Purchase::query()->findOrFail(request()->integer('purchase_id'));
            $this->selectedSupplierId = $purchase->supplier_id;
            $this->source = 'purchase';
            $this->selectedPurchaseId = $purchase->id;
            $this->updatedSelectedPurchaseId();
        }
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function selectedSupplier(): ?Supplier
    {
        return $this->selectedSupplierId ? Supplier::query()->find($this->selectedSupplierId) : null;
    }

    #[Computed]
    public function purchases(): Collection
    {
        return Purchase::query()->where('supplier_id', $this->selectedSupplierId)->latest('date')->latest('id')->get(['id', 'invoice_no', 'date']);
    }

    #[Computed]
    public function selectedPurchase(): ?Purchase
    {
        return $this->selectedPurchaseId ? Purchase::query()->with('items.product:id,name,sku,barcode,stock_quantity')->where('supplier_id', $this->selectedSupplierId)->find($this->selectedPurchaseId) : null;
    }

    #[Computed]
    public function purchaseProductResults(): Collection
    {
        $search = trim($this->purchaseProductSearch);
        if ($this->source !== 'purchase' || ! $this->selectedSupplierId || mb_strlen($search) < 2) {
            return collect();
        }

        return PurchaseItem::query()
            ->with(['product:id,name,sku,barcode', 'purchase:id,invoice_no,date'])
            ->whereHas('purchase', fn (Builder $query): Builder => $query->where('supplier_id', $this->selectedSupplierId))
            ->whereHas('product', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')->limit(15)->get(['id', 'purchase_id', 'product_id', 'quantity']);
    }

    public function selectPurchaseProduct(int $purchaseItemId): void
    {
        abort_unless(auth()->user()?->hasPermission('add_purchase'), 403);
        $item = PurchaseItem::query()->with('product:id,name,sku')
            ->whereHas('purchase', fn (Builder $query): Builder => $query->where('supplier_id', $this->selectedSupplierId))
            ->whereHas('product')->findOrFail($purchaseItemId);

        if ($this->selectedPurchaseId !== $item->purchase_id) {
            $this->selectedPurchaseId = $item->purchase_id;
            $this->updatedSelectedPurchaseId();
        }
        $this->returnItemSearch = $item->product->sku;
        $this->purchaseProductSearch = '';
        $this->resetPage(pageName: 'returnItemsPage');
    }

    #[Computed]
    public function visiblePurchaseLines(): LengthAwarePaginator
    {
        $search = mb_strtolower(trim($this->returnItemSearch));
        $items = $this->purchaseLines->filter(function (PurchaseItem $item) use ($search): bool {
            return $search === '' || collect([$item->product?->name, $item->product?->sku, $item->product?->barcode])
                ->contains(fn (?string $value): bool => str_contains(mb_strtolower($value ?? ''), $search));
        })->values();
        $page = $this->getPage('returnItemsPage');
        $visibleItems = $items->forPage($page, 20)->values();
        foreach ($visibleItems as $item) {
            $this->returnItems[$item->id] ??= ['quantity' => 0, 'refund_price' => round($item->return_value_cents / $item->quantity / 100, 2)];
        }

        return new LengthAwarePaginator($visibleItems, $items->count(), 20, $page, [
            'path' => request()->url(), 'pageName' => 'returnItemsPage',
        ]);
    }

    public function updatedReturnItemSearch(): void
    {
        $this->resetPage(pageName: 'returnItemsPage');
    }

    #[Computed]
    public function pendingFaultyItems(): Collection
    {
        $returned = PurchaseReturnItem::query()->whereNotNull('faulty_item_id')
            ->selectRaw('faulty_item_id, SUM(quantity) as returned_quantity')->groupBy('faulty_item_id')->pluck('returned_quantity', 'faulty_item_id');

        return FaultyItem::query()->with(['product', 'sourceSaleReturn'])->whereHas('product')->where('status', 'pending')->latest()->get()
            ->each(function (FaultyItem $item) use ($returned): void {
                $item->setAttribute('remaining_quantity', max(0, (int) $item->quantity - (int) ($returned[$item->id] ?? 0)));
            })->filter(fn (FaultyItem $item): bool => $item->remaining_quantity > 0);
    }

    #[Computed]
    public function purchaseLines(): Collection
    {
        if (! $this->selectedPurchase) {
            return collect();
        }
        $returned = PurchaseReturnItem::query()->whereIn('purchase_item_id', $this->selectedPurchase->items->pluck('id'))
            ->selectRaw('purchase_item_id, SUM(quantity) as returned_quantity')->groupBy('purchase_item_id')->pluck('returned_quantity', 'purchase_item_id');

        $values = app(PurchaseReturnService::class)->invoiceLineValues($this->selectedPurchase);

        return $this->selectedPurchase->items->each(function ($item) use ($returned, $values): void {
            $item->setAttribute('remaining_quantity', max(0, $item->quantity - (int) ($returned[$item->id] ?? 0)));
            $item->setAttribute('returned_quantity', (int) ($returned[$item->id] ?? 0));
            $item->setAttribute('return_value_cents', $values[$item->id]);
        });
    }

    public function updatedSource(): void
    {
        $this->reset('selectedFaultyItems', 'selectedPurchaseId', 'returnItems', 'replacementItems', 'paidAmount', 'deductionAmount', 'purchaseProductSearch', 'returnItemSearch');
        $this->resetPage(pageName: 'returnItemsPage');
        $this->resetValidation();
    }

    public function updatedSelectedSupplierId(): void
    {
        $this->reset('paidAmount', 'deductionAmount', 'purchaseProductSearch', 'returnItemSearch');
        $this->resetPage(pageName: 'returnItemsPage');
        if ($this->source === 'purchase') {
            $this->reset('selectedPurchaseId', 'returnItems', 'replacementItems');
        }
        $this->resetValidation();
    }

    public function updatedSelectedPurchaseId(): void
    {
        unset($this->selectedPurchase, $this->purchaseLines, $this->visiblePurchaseLines);
        $this->reset('returnItems', 'replacementItems', 'paidAmount', 'deductionAmount', 'returnItemSearch', 'purchaseProductSearch');
        $this->resetPage(pageName: 'returnItemsPage');
    }

    public function updatedSelectedFaultyItems(): void
    {
        $this->returnItems = array_intersect_key($this->returnItems, array_flip($this->selectedFaultyItems));
        foreach ($this->pendingFaultyItems->whereIn('id', $this->selectedFaultyItems) as $item) {
            $this->returnItems[$item->id] ??= ['quantity' => $item->remaining_quantity, 'refund_price' => $item->product->cost_price];
        }
    }

    public function updatedReturnType(): void
    {
        $this->reset('paidAmount', 'deductionAmount');
        if ($this->returnType === 'exchange' && $this->replacementItems === []) {
            $this->useSameProducts();
        }
    }

    public function useSameProducts(): void
    {
        $this->reset('paidAmount', 'deductionAmount');
        $this->replacementItems = [];
        $records = $this->source === 'purchase' ? $this->purchaseLines : $this->pendingFaultyItems;
        foreach ($this->returnItems as $id => $line) {
            $record = $records->firstWhere('id', $id);
            if ($record && (int) $line['quantity'] > 0) {
                $key = $record->product_id;
                $this->replacementItems[$key] ??= ['product_id' => $key, 'name' => $record->product?->name, 'quantity' => 0, 'cost_price' => $line['refund_price']];
                $this->replacementItems[$key]['quantity'] += (int) $line['quantity'];
            }
        }
    }

    #[Computed]
    public function productResults(): Collection
    {
        if (mb_strlen(trim($this->productSearch)) < 2) {
            return collect();
        }

        return Product::query()->where('is_active', true)->where(function ($query): void {
            $query->where('name', 'like', '%'.$this->productSearch.'%')->orWhere('sku', 'like', '%'.$this->productSearch.'%');
        })->orderBy('name')->limit(8)->get();
    }

    public function addReplacement(int $productId): void
    {
        $product = Product::query()->where('is_active', true)->findOrFail($productId);
        $this->reset('paidAmount', 'deductionAmount');
        $this->replacementItems[$productId] ??= ['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'cost_price' => $product->cost_price];
        $this->productSearch = '';
    }

    public function removeReplacement(int $productId): void
    {
        unset($this->replacementItems[$productId]);
        $this->reset('paidAmount', 'deductionAmount');
    }

    public function updatedReturnItems(): void
    {
        $this->reset('paidAmount', 'deductionAmount');
    }

    public function updatedReplacementItems(): void
    {
        $this->reset('paidAmount', 'deductionAmount');
    }

    #[Computed]
    public function summary(): array
    {
        $returnTotal = round((float) collect($this->returnItems)->sum(fn (array $line): float => max(0, (float) ($line['quantity'] ?? 0)) * max(0, round((float) ($line['refund_price'] ?? 0), 2))), 2);
        if ($this->source === 'purchase') {
            $returnCents = 0;
            foreach ($this->purchaseLines as $item) {
                $line = $this->returnItems[$item->id] ?? [];
                $quantity = max(0, (float) ($line['quantity'] ?? 0));
                $price = max(0, round((float) ($line['refund_price'] ?? 0), 2));
                $defaultPrice = round($item->return_value_cents / $item->quantity / 100, 2);
                $returnCents += $price === $defaultPrice
                    ? (int) round($item->return_value_cents * ($item->returned_quantity + $quantity) / $item->quantity) - (int) round($item->return_value_cents * $item->returned_quantity / $item->quantity)
                    : (int) round($price * 100 * $quantity);
            }
            $returnTotal = $returnCents / 100;
        }
        $replacementTotal = $this->returnType === 'exchange' ? round((float) collect($this->replacementItems)->sum(fn (array $line): float => max(0, (float) $line['quantity']) * max(0, round((float) $line['cost_price'], 2))), 2) : 0;
        $supplierOwes = max(0, round($returnTotal - $replacementTotal, 2));
        $weOwe = max(0, round($replacementTotal - $returnTotal, 2));
        $action = $this->returnType === 'exchange' ? $this->balanceAction : $this->returnType;
        $maxDeduction = min($supplierOwes, max(0, (float) $this->selectedSupplier?->due_balance));
        $deduction = $action === 'adjust_due' ? $maxDeduction : ($action === 'split' ? min($maxDeduction, max(0, (float) $this->deductionAmount)) : 0);
        $paid = min($weOwe, max(0, (float) $this->paidAmount));

        return [
            'returned' => $returnTotal, 'replacement' => $replacementTotal, 'supplier_owes' => $supplierOwes, 'we_owe' => $weOwe,
            'deduction' => $deduction, 'max_deduction' => $maxDeduction, 'refund' => $supplierOwes - $deduction,
            'paid' => $paid, 'new_due' => $weOwe - $paid,
            'balance' => (float) $this->selectedSupplier?->due_balance - $deduction + $weOwe - $paid,
        ];
    }

    public function processReturn(PurchaseReturnService $service): void
    {
        abort_unless(auth()->user()?->hasPermission('add_purchase'), 403);
        $this->validate([
            'selectedSupplierId' => ['required', 'integer'],
            'selectedFaultyItems' => [$this->source === 'faulty' ? 'required' : 'nullable', 'array'],
            'returnItems' => ['required', 'array'],
        ]);
        $items = [];
        foreach ($this->returnItems as $id => $line) {
            if ($this->source === 'faulty' && ! in_array($id, $this->selectedFaultyItems)) {
                continue;
            }
            if ($this->source === 'faulty' || (float) ($line['quantity'] ?? 0) != 0) {
                $items[] = ['id' => $id, 'quantity' => $line['quantity'] ?? null, 'refund_price' => $line['refund_price'] ?? null];
            }
        }
        $return = $service->process([
            'supplier_id' => $this->selectedSupplierId, 'purchase_id' => $this->selectedPurchaseId,
            'source' => $this->source, 'items' => $items, 'replacements' => array_values($this->replacementItems),
            'return_type' => $this->returnType, 'balance_action' => $this->balanceAction,
            'deduction' => $this->deductionAmount, 'paid_amount' => $this->paidAmount,
            'notes' => $this->notes, 'operation_token' => $this->operationToken,
        ]);
        $this->reset('selectedFaultyItems', 'selectedSupplierId', 'selectedPurchaseId', 'returnItems', 'replacementItems', 'paidAmount', 'deductionAmount', 'notes', 'productSearch', 'purchaseProductSearch', 'returnItemSearch');
        $this->operationToken = (string) Str::uuid();
        $this->lastReturnNumber = $return->invoice_no;
        unset($this->pendingFaultyItems, $this->selectedSupplier, $this->summary, $this->purchaseLines, $this->selectedPurchase);
        $this->resetPage();
        $this->resetPage(pageName: 'returnItemsPage');
        Flux::toast(variant: 'success', text: __('Purchase return saved: :number', ['number' => $return->invoice_no]));
    }

    #[Computed]
    public function history()
    {
        $history = PurchaseReturn::query()->with(['supplier', 'purchase', 'items.product', 'replacementPurchase.items.product', 'replacementPurchase.payments'])->latest('id')->paginate(10);
        foreach ($history as $return) {
            if ((float) $return->replacementPurchase?->due_amount > 0) {
                $this->exchangePaymentTokens[$return->id] ??= (string) Str::uuid();
            }
        }

        return $history;
    }

    public function payExchangeDue(int $returnId, PurchaseReturnService $service): void
    {
        $return = PurchaseReturn::query()->findOrFail($returnId);
        $this->exchangePaymentTokens[$returnId] ??= (string) Str::uuid();
        $service->payExchangeDue($return, $this->exchangePayments[$returnId] ?? '', $this->exchangePaymentTokens[$returnId]);
        unset($this->exchangePayments[$returnId], $this->exchangePaymentTokens[$returnId], $this->history, $this->selectedSupplier, $this->summary);
        Flux::toast(variant: 'success', text: __('Exchange payment recorded.'));
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
            <flux:heading size="xl" level="1">{{ __('Purchase returns') }}</flux:heading>
            <flux:text>{{ __('Return items, receive replacements, and settle the difference with your supplier.') }}</flux:text>
        </div>
        <flux:button :href="route('purchases.index')" wire:navigate>{{ __('View purchases') }}</flux:button>
    </div>

    @if ($lastReturnNumber)
        <flux:callout variant="success" heading="{{ __('Return saved') }}" text="{{ $lastReturnNumber }}" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" heading="{{ __('Please check the return') }}">
            <ul class="list-inside list-disc text-sm">
                @foreach ($errors->all() as $error)
                    <li wire:key="return-error-{{ $loop->index }}">{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    <form wire:submit="processReturn" class="grid gap-6 lg:grid-cols-3">
        <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
            <flux:card class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('1. Choose supplier and items to return') }}</flux:heading>
                    <flux:text>{{ __('Invoice returns leave saleable stock. Faulty returns use items already set aside.') }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="selectedSupplierId" label="{{ __('Supplier') }}" placeholder="{{ __('Choose supplier') }}">
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}" wire:key="return-supplier-{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="source" label="{{ __('Where are the items?') }}">
                        <option value="faulty">{{ __('Faulty items set aside') }}</option>
                        <option value="purchase">{{ __('Stock from a purchase invoice') }}</option>
                    </flux:select>
                </div>
                @if ($this->selectedSupplier)
                    <div class="rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800">{{ __('Current amount we owe supplier:') }} <strong>Rs {{ number_format((float) $this->selectedSupplier->due_balance, 2) }}</strong></div>
                @endif
                @if ($source === 'purchase')
                    <flux:input wire:model.live.debounce.300ms="purchaseProductSearch" label="{{ __('Search all purchased products') }}" placeholder="{{ __('Product name, SKU or barcode (at least 2 characters)') }}" description="{{ __('Search across all invoices from the selected supplier, then choose a product to open its invoice.') }}" :disabled="! $selectedSupplierId" />
                    @if (mb_strlen(trim($purchaseProductSearch)) >= 2 && $selectedSupplierId)
                        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @forelse ($this->purchaseProductResults as $result)
                                <button type="button" wire:click="selectPurchaseProduct({{ $result->id }})" wire:loading.attr="disabled" class="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800" wire:key="purchase-product-result-{{ $result->id }}">
                                    <span><strong>{{ $result->product->name }}</strong><span class="block text-xs text-zinc-500">{{ $result->product->sku }}</span></span>
                                    <span class="text-zinc-500">{{ $result->purchase->invoice_no }} · {{ $result->purchase->date->format('d M Y') }}</span>
                                </button>
                            @empty
                                <p class="p-3 text-sm text-zinc-500">{{ __('No purchased products match your search for this supplier.') }}</p>
                            @endforelse
                        </div>
                    @endif
                    <flux:select wire:model.live="selectedPurchaseId" label="{{ __('Purchase invoice') }}" placeholder="{{ __('Choose invoice') }}" :disabled="! $selectedSupplierId">
                        @foreach ($this->purchases as $purchase)
                            <option value="{{ $purchase->id }}" wire:key="return-purchase-{{ $purchase->id }}">{{ $purchase->invoice_no }} · {{ $purchase->date->format('d M Y') }}</option>
                        @endforeach
                    </flux:select>
                    <p wire:loading wire:target="selectedPurchaseId,selectPurchaseProduct" role="status" class="text-sm text-zinc-500">{{ __('Loading invoice products…') }}</p>
                    @if ($this->selectedPurchase)
                        <flux:input wire:model.live.debounce.300ms="returnItemSearch" label="{{ __('Search items in this invoice') }}" placeholder="{{ __('Product name, SKU or barcode') }}" description="{{ __('Entered quantities are kept when searching or changing pages. Save this return before opening another invoice.') }}" />
                        <flux:text size="sm">{{ __('Enter quantities for the items you are returning. Prices include the original invoice discount and tax; you may lower the agreed return price. Final totals include invoice rounding.') }}</flux:text>
                        @forelse ($this->visiblePurchaseLines as $item)
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="purchase-return-line-{{ $item->id }}">
                                <div class="mb-3 flex flex-wrap justify-between gap-2">
                                    <div><strong>{{ $item->product?->name ?? __('Unavailable product') }}</strong><p class="text-xs text-zinc-500">{{ $item->product?->sku }}</p></div>
                                    <span class="text-sm text-zinc-500">{{ __('Available to return:') }} {{ $item->remaining_quantity }} · {{ __('In stock:') }} {{ $item->product?->stock_quantity ?? 0 }}</span>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <flux:input wire:model.live="returnItems.{{ $item->id }}.quantity" type="number" min="0" max="{{ min($item->remaining_quantity, $item->product?->stock_quantity ?? 0) }}" step="1" label="{{ __('Return quantity') }}" />
                                    <flux:input wire:model.live="returnItems.{{ $item->id }}.refund_price" type="number" min="0.01" step="0.01" label="{{ __('Agreed value per item (Rs)') }}" />
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('No invoice products match your search.') }}</p>
                        @endforelse
                        {{ $this->visiblePurchaseLines->links() }}
                    @endif
                @else
                    <flux:text size="sm">{{ __('Faulty items do not have a recorded supplier. Check that the chosen supplier accepts these items. You can return part of a faulty batch.') }}</flux:text>
                    <div class="max-h-[32rem] space-y-3 overflow-y-auto">
                        @forelse ($this->pendingFaultyItems as $item)
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="faulty-return-line-{{ $item->id }}">
                                <flux:checkbox wire:model.live="selectedFaultyItems" value="{{ $item->id }}" label="{{ $item->product->name }} · {{ $item->product->sku }}" />
                                <p class="mt-2 text-xs text-zinc-500">{{ __('Available:') }} {{ $item->remaining_quantity }} · {{ __('Customer return:') }} {{ $item->sourceSaleReturn?->invoice_no ?? '—' }}</p>
                                @if (in_array($item->id, $selectedFaultyItems))
                                    <div class="mt-3 grid grid-cols-2 gap-3">
                                        <flux:input wire:model.live="returnItems.{{ $item->id }}.quantity" type="number" min="1" max="{{ $item->remaining_quantity }}" step="1" label="{{ __('Return quantity') }}" />
                                        <flux:input wire:model.live="returnItems.{{ $item->id }}.refund_price" type="number" min="0.01" step="0.01" label="{{ __('Agreed value per item (Rs)') }}" />
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="py-6 text-center text-sm text-zinc-500">{{ __('No faulty items waiting. Choose a purchase invoice to return items from stock.') }}</p>
                        @endforelse
                    </div>
                @endif
            </flux:card>

            <flux:card class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('2. What are you receiving?') }}</flux:heading>
                    <flux:text>{{ __('Choose money, a reduction in old dues, or replacement products.') }}</flux:text>
                </div>
                <flux:select wire:model.live="returnType" label="{{ __('Return arrangement') }}">
                    <option value="adjust_due">{{ __('Reduce old supplier due') }}</option>
                    <option value="cash_refund">{{ __('Receive cash from supplier') }}</option>
                    <option value="split">{{ __('Part cash, part reduce old due') }}</option>
                    <option value="exchange">{{ __('Receive replacement products') }}</option>
                </flux:select>
                @if ($returnType === 'exchange')
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <flux:text>{{ __('Keep the same products or choose any other products.') }}</flux:text>
                        <flux:button type="button" size="sm" wire:click="useSameProducts">{{ __('Use same products') }}</flux:button>
                    </div>
                    <flux:input wire:model.live.debounce.250ms="productSearch" label="{{ __('Add a different replacement product') }}" placeholder="{{ __('Type product name or SKU (at least 2 characters)') }}" />
                    @if ($this->productResults->isNotEmpty())
                        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($this->productResults as $product)
                                <button type="button" wire:click="addReplacement({{ $product->id }})" class="flex w-full items-center justify-between gap-3 p-3 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800" wire:key="replacement-result-{{ $product->id }}"><span>{{ $product->name }} · {{ $product->sku }}</span><span>{{ __('Add') }}</span></button>
                            @endforeach
                        </div>
                    @endif
                    @forelse ($replacementItems as $key => $item)
                        <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="replacement-line-{{ $key }}">
                            <div class="flex items-center justify-between gap-3"><strong class="text-sm">{{ $item['name'] }}</strong><flux:button type="button" size="sm" variant="ghost" wire:click="removeReplacement({{ $key }})">{{ __('Remove') }}</flux:button></div>
                            <div class="grid grid-cols-2 gap-3">
                                <flux:input wire:model.live="replacementItems.{{ $key }}.quantity" type="number" min="1" step="1" label="{{ __('Quantity received') }}" />
                                <flux:input wire:model.live="replacementItems.{{ $key }}.cost_price" type="number" min="0.01" step="0.01" label="{{ __('Cost per item (Rs)') }}" />
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl bg-zinc-50 p-4 text-sm text-zinc-500 dark:bg-zinc-800">{{ __('Add the products the supplier is giving you.') }}</p>
                    @endforelse
                @endif
            </flux:card>
        </div>

        <div class="min-w-0">
            <flux:card class="space-y-5 lg:sticky lg:top-6">
                <flux:heading size="lg">{{ __('3. Check and settle') }}</flux:heading>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt>{{ __('Returned items') }}</dt><dd class="font-semibold">Rs {{ number_format($this->summary['returned'], 2) }}</dd></div>
                    @if ($returnType === 'exchange')
                        <div class="flex justify-between gap-3"><dt>{{ __('Replacement products') }}</dt><dd class="font-semibold">Rs {{ number_format($this->summary['replacement'], 2) }}</dd></div>
                    @endif
                </dl>
                @if ($this->summary['we_owe'] > 0)
                    <div class="rounded-xl bg-amber-50 p-4 text-amber-900 dark:bg-amber-950 dark:text-amber-100"><p class="text-sm">{{ __('We owe supplier the difference') }}</p><p class="text-xl font-bold">Rs {{ number_format($this->summary['we_owe'], 2) }}</p></div>
                    <flux:input wire:model.live="paidAmount" type="number" min="0" max="{{ $this->summary['we_owe'] }}" step="0.01" label="{{ __('Cash we pay now (Rs)') }}" description="{{ __('Enter 0 for full due, part for partial payment, or the full difference to settle.') }}" />
                    <p class="text-sm">{{ __('New due from this exchange:') }} <strong>Rs {{ number_format($this->summary['new_due'], 2) }}</strong></p>
                @elseif ($this->summary['supplier_owes'] > 0)
                    <div class="rounded-xl bg-emerald-50 p-4 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"><p class="text-sm">{{ __('Supplier owes us') }}</p><p class="text-xl font-bold">Rs {{ number_format($this->summary['supplier_owes'], 2) }}</p></div>
                    @if ($returnType === 'exchange')
                        <flux:select wire:model.live="balanceAction" label="{{ __('How is the difference settled?') }}">
                            <option value="adjust_due">{{ __('Reduce old supplier due') }}</option>
                            <option value="cash_refund">{{ __('Receive cash from supplier') }}</option>
                            <option value="split">{{ __('Part cash, part reduce old due') }}</option>
                        </flux:select>
                    @endif
                    @if (($returnType === 'exchange' ? $balanceAction : $returnType) === 'split')
                        <flux:input wire:model.live="deductionAmount" type="number" min="0" max="{{ $this->summary['max_deduction'] }}" step="0.01" label="{{ __('Deduct from old due (Rs)') }}" />
                    @endif
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt>{{ __('Deduct from old due') }}</dt><dd class="font-semibold">Rs {{ number_format($this->summary['deduction'], 2) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>{{ __('Cash supplier gives us') }}</dt><dd class="font-semibold">Rs {{ number_format($this->summary['refund'], 2) }}</dd></div>
                    </dl>
                    @if (($returnType === 'exchange' ? $balanceAction : $returnType) === 'adjust_due' && $this->summary['refund'] > 0)
                        <flux:text size="sm">{{ __('The return exceeds your old due. Collect the remaining amount shown above in cash.') }}</flux:text>
                    @endif
                @elseif ($this->summary['returned'] > 0)
                    <div class="rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ __('Equal value exchange. No money changes hands.') }}</div>
                @endif
                <div class="border-t border-zinc-200 pt-4 text-sm dark:border-zinc-700"><p>{{ __('Total supplier due after this return') }}</p><p class="mt-1 text-2xl font-bold">Rs {{ number_format($this->summary['balance'], 2) }}</p></div>
                <flux:textarea wire:model="notes" label="{{ __('Reason / supplier agreement') }}" placeholder="{{ __('e.g. faulty charging port, supplier agreed to exchange') }}" rows="3" />
                <flux:text size="sm">{{ __('Save only after the supplier has accepted the return and the cash or products shown here have been received.') }}</flux:text>
                <flux:button type="submit" variant="primary" class="w-full" :disabled="! $selectedSupplierId || $this->summary['returned'] <= 0" wire:loading.attr="disabled" wire:target="processReturn">{{ __('Save purchase return') }}</flux:button>
            </flux:card>
        </div>
    </form>

    <flux:card class="space-y-4">
        <div><flux:heading size="lg">{{ __('Return history') }}</flux:heading><flux:text>{{ __('Open a return to see items, replacements, and how the balance was settled.') }}</flux:text></div>
        @forelse ($this->history as $return)
            <details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="purchase-return-history-{{ $return->id }}">
                <summary class="cursor-pointer text-sm"><span class="font-semibold">{{ $return->invoice_no }}</span><span class="ml-2 text-zinc-500">{{ $return->supplier?->name }} · {{ $return->date->format('d M Y') }} · {{ str($return->return_type)->replace('_', ' ')->headline() }}</span></summary>
                <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                    <div class="space-y-2"><p class="font-semibold">{{ __('Items returned') }}</p>
                        @foreach ($return->items as $line)
                            <p wire:key="history-return-line-{{ $line->id }}">{{ $line->product?->name ?? __('Unavailable product') }} × {{ $line->quantity }} · Rs {{ number_format((float) $line->subtotal, 2) }}</p>
                        @endforeach
                        @if ($return->purchase)<p class="text-zinc-500">{{ __('Original invoice:') }} {{ $return->purchase->invoice_no }}</p>@endif
                    </div>
                    <div class="space-y-2">
                        @if ($return->replacementPurchase)
                            <p class="font-semibold">{{ __('Replacement products') }}</p>
                            @foreach ($return->replacementPurchase->items as $line)
                                <p wire:key="history-replacement-line-{{ $line->id }}">{{ $line->product?->name ?? __('Unavailable product') }} × {{ $line->quantity }} · Rs {{ number_format((float) $line->subtotal, 2) }}</p>
                            @endforeach
                            <p>{{ __('Return credit used:') }} Rs {{ number_format((float) $return->replacementPurchase->return_credit_amount, 2) }}</p>
                            <p>{{ __('Cash we paid:') }} Rs {{ number_format((float) $return->replacementPurchase->payments->sum('amount'), 2) }}</p>
                            <p>{{ __('Remaining exchange due:') }} Rs {{ number_format((float) $return->replacementPurchase->due_amount, 2) }}</p>
                            @if ((float) $return->replacementPurchase->due_amount > 0)
                                <form wire:submit="payExchangeDue({{ $return->id }})" class="flex flex-col gap-2 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <flux:input wire:model="exchangePayments.{{ $return->id }}" type="number" min="0.01" max="{{ $return->replacementPurchase->due_amount }}" step="0.01" label="{{ __('Pay exchange due in cash (Rs)') }}" />
                                    <flux:button type="submit" size="sm" wire:loading.attr="disabled">{{ __('Record cash payment') }}</flux:button>
                                </form>
                            @endif
                            <a href="{{ route('purchases.index', ['search' => $return->replacementPurchase->invoice_no]) }}" wire:navigate class="text-violet-600 underline dark:text-violet-400">{{ __('View replacement invoice') }}</a>
                        @endif
                        <p>{{ __('Cash received:') }} Rs {{ number_format((float) $return->refund_amount, 2) }}</p>
                        <p>{{ __('Old due reduced:') }} Rs {{ number_format((float) $return->adjusted_amount, 2) }}</p>
                    </div>
                    @if ($return->notes)<p class="text-zinc-500 md:col-span-2">{{ $return->notes }}</p>@endif
                </div>
            </details>
        @empty
            <p class="py-6 text-center text-sm text-zinc-500">{{ __('No purchase returns recorded yet.') }}</p>
        @endforelse
        {{ $this->history->links() }}
    </flux:card>
</div>
