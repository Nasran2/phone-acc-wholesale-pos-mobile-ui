@props(['sale', 'allowRevert' => false])

@if ($sale && $sale->returns->isNotEmpty())
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
    </section>
@endif
