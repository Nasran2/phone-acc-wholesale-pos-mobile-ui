@props(['sale', 'allowRevert' => false])

@if ($sale && ($sale->returns->isNotEmpty() || ($sale->relationLoaded('dueCredits') && $sale->dueCredits->isNotEmpty())))
    @php
        $dueReduced = (float) $sale->returns->where('return_type', 'adjust_due')->sum('adjusted_amount');
        $cashRefunded = (float) $sale->returns->sum('refund_amount');
    @endphp
    <section class="mt-4 flex flex-col gap-3">
        <h3 class="text-sm font-bold text-zinc-950 dark:text-white">{{ __('Invoice Returns History') }}</h3>
        <div class="grid gap-2 rounded-xl border border-zinc-200 p-3 text-xs dark:border-zinc-700 sm:grid-cols-3">
            <p>{{ __('Net bill after returns') }}<strong class="mt-1 block">Rs {{ number_format(max(0, (float) $sale->grand_total - $dueReduced - $cashRefunded), 2) }}</strong></p>
            <p>{{ __('Due reduced') }}<strong class="mt-1 block">Rs {{ number_format($dueReduced, 2) }}</strong></p>
            <p>{{ __('Cash refunded') }}<strong class="mt-1 block">Rs {{ number_format($cashRefunded, 2) }}</strong></p>
        </div>
        @foreach ($sale->returns as $return)
            <x-sale-return-details :return="$return" :allow-revert="$allowRevert" />
        @endforeach
        @if ($sale->relationLoaded('dueCredits'))
            @foreach ($sale->dueCredits->where('sale_id', '!=', $sale->id) as $credit)
                <div class="rounded-xl border border-emerald-200 p-3 text-xs dark:border-emerald-900" wire:key="allocated-credit-{{ $sale->id }}-{{ $credit->id }}">
                    <p class="font-semibold">{{ __('Customer return credit applied to this bill') }}: {{ $credit->invoice_no }}</p>
                    <p class="mt-1">{{ __('Original invoice') }}: {{ $credit->sale?->invoice_no }} · {{ __('Due reduced') }}: Rs {{ number_format((float) collect($credit->due_allocations['sales'] ?? [])->where('sale_id', $sale->id)->sum('amount'), 2) }}</p>
                </div>
            @endforeach
        @endif
    </section>
@endif
