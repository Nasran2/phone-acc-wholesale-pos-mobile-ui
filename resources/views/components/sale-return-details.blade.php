@props(['return', 'allowRevert' => false])

<article class="rounded-xl border border-amber-200 bg-amber-50/40 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/20" wire:key="return-details-{{ $return->id }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-bold text-zinc-950 dark:text-white">{{ $return->invoice_no }}</h3>
            <p class="mt-1 text-xs text-zinc-500">{{ $return->date?->format('Y-m-d') }} · {{ $return->type_label }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('Original invoice') }}: {{ $return->sale?->invoice_no ?? __('Unavailable') }}</p>
        </div>
        <p class="font-bold text-amber-700 dark:text-amber-300">{{ __('Return value') }}: Rs {{ number_format($return->total_amount, 2) }}</p>
    </div>
    <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
        <p>{{ $return->return_type === 'bill_credit' ? __('Bill credit') : __('Due reduced') }}: <strong>Rs {{ number_format((float) $return->adjusted_amount, 2) }}</strong>
        </p>
        <p>{{ __('Cash refunded') }}: <strong>Rs {{ number_format((float) $return->refund_amount, 2) }}</strong></p>
    </div>
    @if ($return->return_type === 'exchange')
        <p class="mt-2 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ __('Replacement products issued; invoice due and payments unchanged.') }}</p>
    @endif
    <div class="mt-3 divide-y divide-amber-200/50 border-t border-amber-200/50">
        @foreach ($return->items as $item)
            <div class="flex items-start justify-between gap-3 py-2" wire:key="returned-item-{{ $item->id }}">
                <div>
                    <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $item->product?->name ?? __('Deleted product') }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ __('Qty') }} {{ $item->quantity }} × Rs {{ number_format((float) $item->refund_price, 2) }} · {{ $return->faultyItems->contains('product_id', $item->product_id) ? __('Faulty Product') : __('Restock') }}</p>
                </div>
                <span class="shrink-0 font-semibold">Rs {{ number_format((float) $item->subtotal, 2) }}</span>
            </div>
        @endforeach
    </div>
    @if ($allowRevert)
        <x-sale-return-actions :return="$return" />
    @endif
    @if ($allowRevert && $return->return_type !== 'bill_credit' && ! $return->faultyItems->contains('status', 'returned') && auth()->user()?->hasPermission('process_return'))
        <button type="button" wire:click="revertReturn({{ $return->id }})" wire:confirm="Revert this return and restore its stock and financial balances?" class="mt-3 text-xs font-semibold text-rose-600">{{ __('Revert Return') }}</button>
    @endif
    @if ($return->notes)
        <p class="mt-2 whitespace-pre-line text-xs text-zinc-600 dark:text-zinc-400">{{ __('Notes') }}: {{ $return->notes }}</p>
    @endif
</article>
