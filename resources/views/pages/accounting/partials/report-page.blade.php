@php
    $meta = $this->reportMeta;
    [$startDate, $endDate] = $this->filteredPeriod;
@endphp

<div class="flex flex-col gap-6" x-data="{ range: @entangle('dateRange') }">
    <section class="app-card p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-violet-600 dark:text-violet-300">{{ __($meta['eyebrow']) }}</p>
                <h1 class="mt-1 font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __($meta['title']) }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">{{ __($meta['description']) }}</p>
            </div>

            <div class="flex flex-wrap gap-2 items-center">
                <span class="app-chip">{{ __('From') }} {{ $startDate }}</span>
                <span class="app-chip">{{ __('To') }} {{ $endDate }}</span>
                <flux:button wire:click="downloadPdf" icon="arrow-down-tray" size="sm" class="ml-2">
                    {{ __('Download PDF') }}
                </flux:button>
            </div>
        </div>

        <div class="mt-5 flex items-center gap-2 overflow-x-auto border-t border-zinc-100 pt-4 pb-2 -mb-2 no-scrollbar dark:border-zinc-800">
            @foreach ($this->reportPages as $page)
                <a
                    href="{{ route($page['route']) }}"
                    wire:navigate
                    class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold transition {{ request()->routeIs($page['route']) || ($page['route'] === 'accounting.cash-book' && request()->routeIs('accounting.index')) ? 'bg-violet-600 text-white shadow-sm' : 'bg-zinc-50 text-zinc-500 hover:bg-violet-50 hover:text-violet-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-violet-950/40 dark:hover:text-violet-300' }}"
                >
                    {{ __($page['label']) }}
                </a>
            @endforeach
        </div>
    </section>

    <section class="grid grid-cols-2 gap-3 xl:grid-cols-5">
        <div class="app-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ __('Accounts Receivable') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-rose-600">Rs {{ number_format($this->totalReceivables, 2) }}</p>
            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Customer dues') }}</p>
        </div>

        <div class="app-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ __('Accounts Payable') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-rose-600">Rs {{ number_format($this->totalPayables, 2) }}</p>
            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Supplier dues') }}</p>
        </div>

        <div class="app-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ __('Cash In') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-emerald-600">Rs {{ number_format($this->totalCashInflow, 2) }}</p>
            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Filtered debits') }}</p>
        </div>

        <div class="app-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ __('Cash Out') }}</p>
            <p class="mt-1 font-display text-xl font-bold text-rose-600">Rs {{ number_format($this->totalCashOutflow, 2) }}</p>
            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Filtered credits') }}</p>
        </div>

        <div class="app-card p-4 col-span-2 xl:col-span-1">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ __('Net Flow') }}</p>
            <p class="mt-1 font-display text-xl font-bold {{ $this->netCashFlow >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">Rs {{ number_format($this->netCashFlow, 2) }}</p>
            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Debit minus credit') }}</p>
        </div>
    </section>

    <section class="app-card p-4">
        <div class="flex flex-col gap-4">
            <div class="flex items-center gap-2 overflow-x-auto pb-2 -mb-2 no-scrollbar">
                <span class="shrink-0 text-xs font-semibold uppercase tracking-wider text-zinc-400">{{ __('Period') }}</span>

                @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7days' => 'Last 7 Days', '30days' => 'Last 30 Days', 'custom' => 'Custom Range'] as $rangeValue => $rangeLabel)
                    <button
                        type="button"
                        class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold transition"
                        :class="range === '{{ $rangeValue }}' ? 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300' : 'bg-transparent text-zinc-500 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        wire:click="$set('dateRange', '{{ $rangeValue }}')"
                    >
                        {{ __($rangeLabel) }}
                    </button>
                @endforeach
            </div>

            <div x-cloak x-show="range === 'custom'" class="grid gap-3 border-t border-zinc-100 pt-3 sm:grid-cols-2 dark:border-zinc-800">
                <flux:input wire:model.live="customStartDate" type="date" :label="__('Start Date')" />
                <flux:input wire:model.live="customEndDate" type="date" :label="__('End Date')" />
            </div>

            <div class="grid gap-3 border-t border-zinc-100 pt-3 md:grid-cols-[1fr_220px_auto] dark:border-zinc-800">
                <flux:input wire:model.live.debounce.350ms="search" :label="__('Search')" placeholder="Reference, note, account..." />

                <flux:select wire:model.live="paymentMethod" :label="__('Payment Method')">
                    <option value="all">{{ __('All Methods') }}</option>
                    @foreach ($this->availablePaymentMethods as $method)
                        <option value="{{ $method }}">{{ $this->methodLabel($method) }}</option>
                    @endforeach
                </flux:select>

                <div class="flex items-end">
                    <flux:button type="button" variant="ghost" wire:click="clearFilters" class="w-full md:w-auto">
                        {{ __('Reset') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </section>

    <section class="app-card overflow-hidden">
        <div class="flex flex-col gap-1 border-b border-zinc-100 p-5 dark:border-zinc-800">
            <h2 class="font-display text-base font-semibold text-zinc-950 dark:text-white">{{ __($meta['title']) }}</h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ number_format($this->reportTransactions->count()) }} {{ __('entries') }} ·
                {{ $this->paymentMethod === 'all' ? __('All methods') : $this->methodLabel($this->paymentMethod) }}
            </p>
        </div>

        <div class="overflow-x-auto">
            @if ($reportType === 'daily-cash-closing')
                <table class="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-800">
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-emerald-600">{{ __('Cash In') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-rose-600">{{ __('Cash Out') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Net') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Closing Balance') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Entries') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                        @forelse ($this->dailyClosingRows as $row)
                            <tr wire:key="daily-closing-{{ $row['date'] }}" class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-5 py-3.5 font-semibold text-zinc-950 dark:text-white">{{ $row['date'] }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-emerald-600">Rs {{ number_format($row['debit'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-rose-600">Rs {{ number_format($row['credit'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold {{ $row['net'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">Rs {{ number_format($row['net'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-black text-zinc-950 dark:text-white">Rs {{ number_format($row['closing_balance'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right">{{ $row['count'] }}</td>
                            </tr>
                        @empty
                            @include('pages.accounting.partials.empty-row', ['columns' => 6, 'message' => $meta['empty']])
                        @endforelse
                    </tbody>
                </table>
            @elseif ($reportType === 'daily-register-closing')
                <table class="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-800">
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-emerald-600">{{ __('Sales Receipts') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-emerald-600">{{ __('Due Collections') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-rose-600">{{ __('Purchase Payments') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-rose-600">{{ __('Expenses') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Net') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                        @forelse ($this->registerClosingRows as $row)
                            <tr wire:key="register-closing-{{ $row['date'] }}" class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-5 py-3.5 font-semibold text-zinc-950 dark:text-white">{{ $row['date'] }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-emerald-600">Rs {{ number_format($row['sales_receipts'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-emerald-600">Rs {{ number_format($row['due_collections'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-rose-600">Rs {{ number_format($row['purchase_payments'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-rose-600">Rs {{ number_format($row['expenses'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-black {{ $row['net'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">Rs {{ number_format($row['net'], 2) }}</td>
                            </tr>
                        @empty
                            @include('pages.accounting.partials.empty-row', ['columns' => 6, 'message' => $meta['empty']])
                        @endforelse
                    </tbody>
                </table>
            @elseif ($reportType === 'payment-method-report')
                <table class="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-800">
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Payment Method') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-emerald-600">{{ __('Debit') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-rose-600">{{ __('Credit') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Net') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Entries') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                        @forelse ($this->paymentMethodRows as $row)
                            <tr wire:key="method-row-{{ $row['method'] }}" class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <flux:badge size="sm" color="zinc">{{ $this->methodLabel($row['method']) }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-emerald-600">Rs {{ number_format($row['debit'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-rose-600">Rs {{ number_format($row['credit'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-black {{ $row['net'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">Rs {{ number_format($row['net'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right">{{ $row['count'] }}</td>
                            </tr>
                        @empty
                            @include('pages.accounting.partials.empty-row', ['columns' => 5, 'message' => $meta['empty']])
                        @endforelse
                    </tbody>
                </table>
            @elseif ($reportType === 't-accounts')
                @if($this->tAccountRows->isEmpty())
                    <table class="w-full border-collapse text-left text-xs">
                        <tbody>
                            @include('pages.accounting.partials.empty-row', ['columns' => 1, 'message' => $meta['empty']])
                        </tbody>
                    </table>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-5">
                        @foreach ($this->tAccountRows as $row)
                            <div wire:key="t-account-{{ str($row['account'])->slug() }}" class="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
                                <div class="border-b-2 border-zinc-900 dark:border-white p-3 text-center">
                                    <h3 class="font-display text-sm font-bold tracking-wider text-zinc-950 dark:text-white">{{ $row['account'] }}</h3>
                                </div>
                                <div class="grid grid-cols-2 grow">
                                    <div class="flex flex-col border-r border-zinc-900 dark:border-white p-2">
                                        <div class="text-[10px] font-bold uppercase text-zinc-400 mb-2 px-1">{{ __('Debit') }}</div>
                                        <div class="flex flex-col gap-2 grow">
                                            @foreach($row['debits'] as $txn)
                                                <div class="flex justify-between items-start text-xs px-1">
                                                    <span class="text-zinc-500 truncate mr-2" title="{{ $txn['date']->format('m/d') }} - {{ $txn['description'] }}">
                                                        {{ $txn['date']->format('m/d') }}
                                                    </span>
                                                    <span class="font-semibold text-emerald-600">{{ number_format($txn['debit'], 2) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between items-center px-1">
                                            <span class="text-[10px] font-semibold text-zinc-400">{{ __('Total') }}</span>
                                            <span class="text-xs font-bold text-zinc-950 dark:text-white">{{ number_format($row['total_debit'], 2) }}</span>
                                        </div>
                                        @if($row['balance'] >= 0)
                                            <div class="mt-1 flex justify-between items-center px-1">
                                                <span class="text-[10px] font-bold text-emerald-600 uppercase">{{ __('Bal') }}</span>
                                                <span class="text-xs font-black text-emerald-600">{{ number_format($row['balance'], 2) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex flex-col p-2">
                                        <div class="text-[10px] font-bold uppercase text-zinc-400 mb-2 px-1">{{ __('Credit') }}</div>
                                        <div class="flex flex-col gap-2 grow">
                                            @foreach($row['credits'] as $txn)
                                                <div class="flex justify-between items-start text-xs px-1">
                                                    <span class="text-zinc-500 truncate mr-2" title="{{ $txn['date']->format('m/d') }} - {{ $txn['description'] }}">
                                                        {{ $txn['date']->format('m/d') }}
                                                    </span>
                                                    <span class="font-semibold text-rose-600">{{ number_format($txn['credit'], 2) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between items-center px-1">
                                            <span class="text-[10px] font-semibold text-zinc-400">{{ __('Total') }}</span>
                                            <span class="text-xs font-bold text-zinc-950 dark:text-white">{{ number_format($row['total_credit'], 2) }}</span>
                                        </div>
                                        @if($row['balance'] < 0)
                                            <div class="mt-1 flex justify-between items-center px-1">
                                                <span class="text-[10px] font-bold text-rose-600 uppercase">{{ __('Bal') }}</span>
                                                <span class="text-xs font-black text-rose-600">{{ number_format(abs($row['balance']), 2) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                <table class="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-400 dark:border-zinc-800">
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Date') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Transaction / Reference') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Account') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 font-bold uppercase tracking-wider">{{ __('Method') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-emerald-600">{{ __('Debit') }}</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider text-rose-600">{{ __('Credit') }}</th>
                            @if ($reportType === 'cash-balance')
                                <th class="whitespace-nowrap px-5 py-3 text-right font-bold uppercase tracking-wider">{{ __('Balance') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                        @foreach ($reportType === 'cash-balance' ? $this->balanceRows : $this->reportTransactions as $transaction)
                            <tr wire:key="transaction-{{ $loop->index }}-{{ $transaction['raw_date']?->timestamp ?? $transaction['date']->timestamp }}" class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40">
                                <td class="whitespace-nowrap px-5 py-3.5">{{ $transaction['date']->format('Y-m-d') }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <span class="block max-w-[320px] truncate font-semibold text-zinc-950 dark:text-white">{{ $transaction['description'] }}</span>
                                    @if ($transaction['reference'])
                                        <span class="mt-1 block text-[10px] font-semibold uppercase tracking-wider text-zinc-400">{{ $transaction['reference'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 whitespace-nowrap">{{ $transaction['account'] }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 whitespace-nowrap">
                                    <flux:badge size="sm" color="zinc">{{ $this->methodLabel($transaction['method']) }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-emerald-600">
                                    {{ $transaction['debit'] > 0 ? 'Rs '.number_format($transaction['debit'], 2) : '-' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right font-bold text-rose-600">
                                    {{ $transaction['credit'] > 0 ? 'Rs '.number_format($transaction['credit'], 2) : '-' }}
                                </td>
                                @if ($reportType === 'cash-balance')
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right font-black {{ $transaction['balance'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                        Rs {{ number_format($transaction['balance'], 2) }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach

                        @if (($reportType === 'cash-balance' ? $this->balanceRows : $this->reportTransactions)->isEmpty())
                            @include('pages.accounting.partials.empty-row', ['columns' => $reportType === 'cash-balance' ? 7 : 6, 'message' => $meta['empty']])
                        @endif
                    </tbody>
                </table>
            @endif
        </div>
    </section>
</div>
