<?php

use App\Models\FaultyItem;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

new #[Title('Purchase Returns')] class extends Component
{
    public array $selectedFaultyItems = [];
    public ?int $selectedSupplierId = null;
    public string $returnType = 'adjust_due';
    public ?string $notes = null;

    #[Computed]
    public function pendingFaultyItems()
    {
        return FaultyItem::with(['product', 'sourceSaleReturn'])
            ->where('status', 'pending')
            ->latest()
            ->get();
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::orderBy('name')->get();
    }

    public function processReturn()
    {
        $this->validate([
            'selectedFaultyItems' => 'required|array|min:1',
            'selectedSupplierId' => 'required|exists:suppliers,id',
            'returnType' => 'required|in:exchange,adjust_due,cash_refund',
        ]);

        $itemsToReturn = FaultyItem::whereIn('id', $this->selectedFaultyItems)->where('status', 'pending')->get();
        if ($itemsToReturn->isEmpty()) {
            Flux::toast(__('No valid faulty items selected.'))->variant('danger');
            return;
        }

        DB::transaction(function () use ($itemsToReturn) {
            $supplier = Supplier::findOrFail($this->selectedSupplierId);

            $refundTotal = 0;
            foreach ($itemsToReturn as $item) {
                // Here we could allow user to set refund_price, but for simplicity we use product cost_price
                $refundTotal += $item->quantity * (float) $item->product->cost_price;
            }

            $adjustedAmount = 0;
            $refundAmount = 0;

            if ($this->returnType === 'adjust_due') {
                $adjustedAmount = min($refundTotal, (float) $supplier->due_balance);
                $refundAmount = max(0.00, $refundTotal - $adjustedAmount);
            } elseif ($this->returnType === 'cash_refund') {
                $refundAmount = $refundTotal;
            }

            // Create return number
            $returnNo = 'PRET-'.now()->format('ymd').'-'.random_int(100, 999);
            while (PurchaseReturn::where('invoice_no', $returnNo)->exists()) {
                $returnNo = 'PRET-'.now()->format('ymd').'-'.random_int(100, 999);
            }

            $purchaseReturn = PurchaseReturn::create([
                'supplier_id' => $supplier->id,
                'invoice_no' => $returnNo,
                'date' => today()->toDateString(),
                'return_type' => $this->returnType,
                'refund_amount' => $refundAmount,
                'adjusted_amount' => $adjustedAmount,
                'notes' => $this->notes ?: 'Return of faulty items to supplier.',
            ]);

            foreach ($itemsToReturn as $item) {
                $purchaseReturn->items()->create([
                    'product_id' => $item->product_id,
                    'faulty_item_id' => $item->id,
                    'quantity' => $item->quantity,
                    'refund_price' => (float) $item->product->cost_price,
                    'subtotal' => $item->quantity * (float) $item->product->cost_price,
                ]);

                $item->update(['status' => 'returned']);
            }

            // Adjust Supplier Due
            if ($adjustedAmount > 0) {
                $supplier->decrement('due_balance', $adjustedAmount);
            }

            if ($this->returnType === 'exchange') {
                // If exchange, we get replacement stock back, so increment stock
                foreach ($itemsToReturn as $item) {
                    $item->product->increment('stock_quantity', $item->quantity);
                }
            }

            Flux::toast(__('Purchase Return processed successfully. Return #: ' . $returnNo))->variant('success');
            
            $this->reset(['selectedFaultyItems', 'selectedSupplierId', 'returnType', 'notes']);
        });
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Process Purchase Returns') }}</flux:heading>
            <flux:subheading size="lg" class="mb-6">{{ __('Return faulty products back to suppliers.') }}</flux:subheading>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <flux:card>
                <div class="mb-4">
                    <h3 class="text-lg font-medium text-zinc-900 dark:text-zinc-100">{{ __('Pending Faulty Items') }}</h3>
                    <p class="text-sm text-zinc-500">{{ __('Select the faulty items to return to a supplier.') }}</p>
                </div>

                @if($this->pendingFaultyItems->isEmpty())
                    <div class="text-center py-8 text-zinc-500">
                        {{ __('No pending faulty items found.') }}
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($this->pendingFaultyItems as $item)
                            <div class="flex items-center gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:checkbox wire:model="selectedFaultyItems" value="{{ $item->id }}" />
                                
                                <div class="flex-1">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $item->product?->name }}</div>
                                    <div class="text-sm text-zinc-500">
                                        {{ __('SKU:') }} {{ $item->product?->sku }} | 
                                        {{ __('Quantity:') }} <span class="font-bold">{{ $item->quantity }}</span>
                                    </div>
                                    <div class="text-xs text-zinc-400 mt-1">
                                        {{ __('From POS Return:') }} {{ $item->sourceSaleReturn?->invoice_no ?? 'Unknown' }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium">Cost: {{ number_format((float) $item->product?->cost_price, 2) }}</div>
                                    <div class="text-sm text-zinc-500">Subtotal: {{ number_format($item->quantity * (float) $item->product?->cost_price, 2) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </div>

        <div class="space-y-6">
            <flux:card>
                <div class="mb-4">
                    <h3 class="text-lg font-medium text-zinc-900 dark:text-zinc-100">{{ __('Return Details') }}</h3>
                </div>

                <div class="space-y-4">
                    <flux:select wire:model="selectedSupplierId" label="{{ __('Supplier') }}">
                        <option value="">{{ __('Select a supplier') }}</option>
                        @foreach($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }} (Due: {{ number_format((float) $supplier->due_balance, 2) }})</option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="returnType" label="{{ __('Return Type') }}">
                        <option value="adjust_due">{{ __('Adjust Due (Deduct from supplier due)') }}</option>
                        <option value="exchange">{{ __('Exchange (Receive replacement stock)') }}</option>
                        <option value="cash_refund">{{ __('Cash Refund') }}</option>
                    </flux:select>

                    <flux:textarea wire:model="notes" label="{{ __('Notes') }}" rows="3" />

                    <flux:button wire:click="processReturn" variant="primary" class="w-full" :disabled="empty($selectedFaultyItems) || empty($selectedSupplierId)">
                        {{ __('Process Purchase Return') }}
                    </flux:button>
                </div>
            </flux:card>
        </div>
    </div>
</div>
