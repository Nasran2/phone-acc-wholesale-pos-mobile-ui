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
                    @php($sale = $cheque->paymentable instanceof \App\Models\Sale ? $cheque->paymentable : null)
                    @php($purchase = $cheque->paymentable instanceof \App\Models\Purchase ? $cheque->paymentable : null)
                    @php($supplier = $cheque->paymentable instanceof \App\Models\Supplier ? $cheque->paymentable : null)
                    @php($customer = $cheque->paymentable instanceof \App\Models\Customer ? $cheque->paymentable : null)
                    @php($sourceSale = $cheque->sourcePayment?->paymentable instanceof \App\Models\Sale ? $cheque->sourcePayment->paymentable : null)
                    <div class="flex flex-col justify-between gap-3 rounded-2xl border border-amber-100 bg-white p-3 shadow-sm dark:border-amber-900/30 dark:bg-zinc-900/85" wire:key="cheque-alert-{{ $cheque->id }}">
                        <div class="min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-black text-zinc-950 dark:text-zinc-50">
                                    @if ($purchase)
                                        {{ $purchase->supplier?->name ?? __('Unknown Supplier') }}
                                    @elseif ($supplier)
                                        {{ $supplier->name ?? __('Unknown Supplier') }}
                                    @elseif ($customer)
                                        {{ $customer->name ?? __('Unknown Customer') }}
                                    @else
                                        {{ $sale?->customer?->name ?? __('Unknown Customer') }}
                                    @endif
                                </p>
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-extrabold text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                    {{ $purchase?->invoice_no ?? $sale?->invoice_no ?? ($cheque->reference ?: ($customer ? 'CUST-PAY' : 'SUPP-PAY')) }}
                                </span>
                            </div>
                            @if ($purchase)
                                <p class="mt-1 text-[10px] font-black uppercase tracking-wide text-violet-600 dark:text-violet-300">
                                    {{ $cheque->cheque_type === 'party' ? __('Party cheque to supplier') : __('Own cheque to supplier') }}
                                </p>
                            @elseif ($supplier)
                                <p class="mt-1 text-[10px] font-black uppercase tracking-wide text-violet-600 dark:text-violet-300">
                                    {{ __('Supplier payoff cheque') }}
                                </p>
                            @elseif ($customer)
                                <p class="mt-1 text-[10px] font-black uppercase tracking-wide text-violet-600 dark:text-violet-300">
                                    {{ __('Customer due cheque') }}
                                </p>
                            @endif
                            <p class="mt-1 text-[10px] font-semibold text-zinc-400 dark:text-zinc-500">
                                {{ __('Due date:') }} <span class="font-bold text-zinc-600 dark:text-zinc-300">{{ $cheque->cheque_date?->format('Y-m-d') }}</span>
                            </p>
                            @if (($purchase || $supplier) && $cheque->cheque_type === 'party')
                                <p class="mt-0.5 text-[10px] font-semibold text-zinc-500 dark:text-zinc-400">
                                    {{ __('Customer:') }} <span class="font-bold">{{ $sourceSale?->customer?->name ?? $cheque->partyCustomer?->name ?? __('Unknown Customer') }}</span>
                                </p>
                            @endif
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

    <flux:modal wire:model.self="returnResolutionModalOpen">
        <div class="w-full max-w-2xl space-y-4">
            <div>
                <h3 class="font-display text-lg font-semibold text-zinc-950 dark:text-white">{{ __('Settle returned supplier cheque') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Choose how this supplier balance should be handled after the cheque return.') }}</p>
            </div>

            <flux:select wire:model.live="settlementMethod" :label="__('Settlement Method')">
                <option value="pay_later">{{ __('Pay Later') }}</option>
                <option value="cash">{{ __('Cash') }}</option>
                <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                <option value="party_cheque">{{ __('Another Party Cheque') }}</option>
            </flux:select>

            @if ($settlementMethod !== 'pay_later')
                <flux:input type="date" wire:model="settlementDate" :label="__('Date')" required />
            @endif

            @if (in_array($settlementMethod, ['cash', 'bank_transfer'], true))
                <flux:input wire:model="settlementReference" :label="__('Reference')" placeholder="Receipt or transaction reference" />
            @elseif ($settlementMethod === 'party_cheque')
                <div class="relative" x-data="{ open: false }" @click.away="open = false">
                    <flux:input wire:model.live.debounce.150ms="partyChequeSearch" :label="__('Replacement Customer Cheque No')" placeholder="Search pending customer cheque..." @focus="open = true" />

                    @if ($this->replacementPartyCheques->isNotEmpty())
                        <div x-cloak x-show="open" class="absolute z-40 mt-2 max-h-60 w-full overflow-y-auto rounded-2xl border border-zinc-100 bg-white p-2 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                            @foreach ($this->replacementPartyCheques as $partyCheque)
                                @php($partySale = $partyCheque->paymentable)
                                <button type="button" wire:click="selectReplacementPartyCheque({{ $partyCheque->id }})" @click="open = false" class="w-full rounded-xl p-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-50">{{ $partyCheque->cheque_no ?: $partyCheque->reference }}</span>
                                        <span class="text-xs font-black text-violet-600 dark:text-violet-300">Rs {{ number_format($partyCheque->amount, 2) }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $partySale?->customer?->name ?? __('Unknown Customer') }} · {{ $partySale?->invoice_no }} · {{ $partyCheque->cheque_date?->format('Y-m-d') }}
                                    </p>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('returnResolutionModalOpen', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="settleReturnedSupplierCheque">
                    {{ __('Confirm') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
