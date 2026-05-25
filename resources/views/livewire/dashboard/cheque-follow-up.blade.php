<div>
    @php($cheques = $this->getActionableChequePayments())
    @if ($cheques->isNotEmpty())
        <section class="rounded-[1.75rem] border border-amber-250 bg-amber-50/50 p-4 shadow-[0_14px_34px_rgba(245,158,11,0.06)] dark:border-amber-900/30 dark:bg-amber-950/10">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-600 dark:text-amber-400">{{ __('Cheque follow-up') }}</p>
                    <h2 class="text-sm font-black text-zinc-950 dark:text-zinc-50">{{ __('Pending cheques near due date') }}</h2>
                </div>
                <span class="rounded-full border border-amber-200/50 bg-white px-3 py-1 text-xs font-black text-amber-700 shadow-sm dark:border-amber-900/30 dark:bg-zinc-900 dark:text-amber-300">
                    {{ $cheques->count() }}
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cheques as $cheque)
                    @php($sale = $cheque->paymentable)
                    <div class="flex flex-col justify-between gap-3 rounded-2xl border border-amber-100 bg-white p-3 shadow-sm dark:border-amber-900/30 dark:bg-zinc-900/85" wire:key="cheque-alert-{{ $cheque->id }}">
                        <div class="min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-black text-zinc-950 dark:text-zinc-50">{{ $sale?->customer?->name ?? __('Unknown Customer') }}</p>
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-extrabold text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                    {{ $sale?->invoice_no }}
                                </span>
                            </div>
                            <p class="mt-1 text-[10px] font-semibold text-zinc-400 dark:text-zinc-500">
                                {{ __('Due date:') }} <span class="font-bold text-zinc-600 dark:text-zinc-300">{{ $cheque->cheque_date?->format('Y-m-d') }}</span>
                            </p>
                            @if ($cheque->cheque_bank || $cheque->cheque_no)
                                <p class="mt-0.5 font-mono text-[9.5px] text-zinc-500 dark:text-zinc-400">
                                    {{ $cheque->cheque_bank }} {{ $cheque->cheque_no ? '#'.$cheque->cheque_no : '' }}
                                </p>
                            @endif
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-3 border-t border-zinc-100 pt-2.5 dark:border-zinc-800/80">
                            <span class="text-xs font-bold text-amber-700 dark:text-amber-400">Rs {{ number_format($cheque->amount, 2) }}</span>
                            <div class="flex gap-1.5">
                                <button type="button" wire:click="passChequePayment({{ $cheque->id }})" class="rounded-xl bg-emerald-600 px-3 py-1.5 text-[10px] font-black text-white shadow-sm transition hover:bg-emerald-500 active:scale-95 dark:bg-emerald-600">
                                    {{ __('Passed') }}
                                </button>
                                <button type="button" wire:click="returnChequePayment({{ $cheque->id }})" class="rounded-xl bg-rose-50 px-3 py-1.5 text-[10px] font-black text-rose-600 ring-1 ring-rose-100 transition hover:bg-rose-100 active:scale-95 dark:bg-rose-950/30 dark:text-rose-400 dark:ring-rose-900/30 dark:hover:bg-rose-950/50">
                                    {{ __('Return') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
