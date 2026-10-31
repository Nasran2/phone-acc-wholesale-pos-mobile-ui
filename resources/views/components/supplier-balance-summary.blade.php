@props(['summary' => [], 'compact' => false])

<dl {{ $attributes->class(['grid min-w-0 grid-cols-2 gap-2 sm:grid-cols-3']) }}>
    <div @class(['min-w-0 rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50', 'p-2.5' => $compact, 'p-4' => ! $compact])>
        <dt class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ __('Total Due') }}</dt>
        <dd @class(['mt-1 break-words font-bold tabular-nums text-zinc-900 dark:text-white', 'text-sm' => $compact, 'text-base sm:text-xl' => ! $compact])>Rs {{ number_format($summary['total_due'] ?? 0, 2) }}</dd>
    </div>
    <div @class(['min-w-0 rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30', 'p-2.5' => $compact, 'p-4' => ! $compact])>
        <dt class="text-xs font-semibold text-amber-700 dark:text-amber-400">{{ __('Total Pending') }}</dt>
        <dd @class(['mt-1 break-words font-bold tabular-nums text-amber-700 dark:text-amber-400', 'text-sm' => $compact, 'text-base sm:text-xl' => ! $compact])>Rs {{ number_format($summary['pending'] ?? 0, 2) }}</dd>
    </div>
    <div @class(['col-span-2 min-w-0 rounded-xl border border-violet-200 bg-violet-50 sm:col-span-1 dark:border-violet-900 dark:bg-violet-950/30', 'p-2.5' => $compact, 'p-4' => ! $compact])>
        <dt class="text-xs font-semibold text-violet-700 dark:text-violet-300">{{ __('Due After Pending') }}</dt>
        <dd @class(['mt-1 break-words font-bold tabular-nums text-violet-700 dark:text-violet-300', 'text-sm' => $compact, 'text-base sm:text-xl' => ! $compact])>Rs {{ number_format($summary['due_after_pending'] ?? 0, 2) }}</dd>
    </div>
</dl>
