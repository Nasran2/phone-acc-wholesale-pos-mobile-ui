@props(['amount', 'status' => null])

<div @class([
    'text-right',
    'text-amber-700 dark:text-amber-400' => $status === 'pending',
    'text-emerald-600 dark:text-emerald-400' => $status === 'passed',
    'text-rose-600 dark:text-rose-400' => $status === 'returned',
    'text-zinc-700 dark:text-zinc-300' => ! in_array($status, ['pending', 'passed', 'returned'], true),
])>
    <p class="text-[10px] font-semibold uppercase tracking-wider">{{ __('Cheque Amount') }}</p>
    <p class="text-sm font-bold tabular-nums whitespace-nowrap">Rs {{ number_format((float) $amount, 2) }}</p>
</div>
