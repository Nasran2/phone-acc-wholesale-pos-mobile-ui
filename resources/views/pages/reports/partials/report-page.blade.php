@php
    $meta = $this->meta;
    [$startDate, $endDate] = $this->filteredPeriod;
    $dateSensitive = $meta['date_sensitive'];
@endphp

<div class="flex flex-col gap-5 print:block" x-data="{ range: @entangle('dateRange') }">
    <div class="screen-only flex flex-col gap-5">
        <section class="app-card p-4 sm:p-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-wider text-violet-600 dark:text-violet-300">{{ __($meta['eyebrow']) }}</p>
                    <h1 class="mt-1 font-display text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ __($meta['title']) }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">{{ __($meta['description']) }}</p>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    <flux:button type="button" variant="primary" icon="printer" onclick="window.print()" class="w-full sm:w-auto">
                        {{ __('PDF / Print') }}
                    </flux:button>
                    <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="clearFilters" class="w-full sm:w-auto">
                        {{ __('Reset') }}
                    </flux:button>
                </div>
            </div>

            <div class="mt-5 flex gap-2 overflow-x-auto border-t border-zinc-100 pt-4 dark:border-zinc-800">
                @foreach ($this->reportPages as $page)
                    <a
                        href="{{ route($page['route']) }}"
                        wire:navigate
                        class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-bold transition {{ request()->routeIs($page['route']) || ($page['route'] === 'reports.sales' && request()->routeIs('reports.index')) ? 'bg-violet-600 text-white shadow-sm' : 'bg-zinc-50 text-zinc-500 hover:bg-violet-50 hover:text-violet-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-violet-950/40 dark:hover:text-violet-300' }}"
                    >
                        {{ __($page['label']) }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->summary as $item)
                <div class="rounded-2xl border p-4 {{ $this->toneClass($item['tone'], 'box') }}">
                    <p class="text-[10px] font-black uppercase tracking-wider opacity-75">{{ __($item['label']) }}</p>
                    <p class="mt-1 font-display text-xl font-bold">{{ $item['value'] }}</p>
                </div>
            @endforeach
        </section>

        <section class="app-card p-4">
            <div class="grid gap-3 lg:grid-cols-[1fr_auto]">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @if ($dateSensitive)
                        <flux:select wire:model.live="dateRange" :label="__('Period')">
                            <option value="today">{{ __('Today') }}</option>
                            <option value="yesterday">{{ __('Yesterday') }}</option>
                            <option value="7days">{{ __('Last 7 Days') }}</option>
                            <option value="30days">{{ __('Last 30 Days') }}</option>
                            <option value="custom">{{ __('Custom Range') }}</option>
                        </flux:select>

                        <div x-show="range === 'custom'" x-cloak>
                            <flux:input wire:model.live="customStartDate" type="date" :label="__('Start Date')" />
                        </div>

                        <div x-show="range === 'custom'" x-cloak>
                            <flux:input wire:model.live="customEndDate" type="date" :label="__('End Date')" />
                        </div>
                    @endif

                    @if ($reportType !== 'profit-loss' && $reportType !== 'expenses' && $reportType !== 'receives' && $reportType !== 'debits')
                        <flux:select wire:model.live="reportStatus" :label="__('Filter')">
                            @foreach ($this->statusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </flux:select>
                    @endif

                    @if ($this->usesPaymentMethodFilter())
                        <flux:select wire:model.live="paymentMethod" :label="__('Payment Method')">
                            <option value="all">{{ __('All Methods') }}</option>
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="qr">{{ __('QR') }}</option>
                            <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                            <option value="cheque">{{ __('Cheque') }}</option>
                        </flux:select>
                    @endif
                </div>

                @if ($reportType !== 'profit-loss')
                    <div class="min-w-0 lg:w-80">
                        <flux:input wire:model.live.debounce.350ms="search" :label="__('Search')" placeholder="Invoice, name, phone, SKU..." />
                    </div>
                @endif
            </div>
        </section>

        <section class="app-card overflow-hidden">
            <div class="border-b border-zinc-100 p-4 dark:border-zinc-800 sm:p-5">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="font-display text-base font-semibold text-zinc-950 dark:text-white">{{ __($meta['title']) }}</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ number_format($this->rows->count()) }} {{ __('records') }}
                            @if ($dateSensitive)
                                · {{ $startDate }} {{ __('to') }} {{ $endDate }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                @include('pages.reports.partials.report-table', ['print' => false])
            </div>

            <div class="grid gap-3 p-3 md:hidden">
                @forelse ($this->rows as $row)
                    <article class="rounded-xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" wire:key="report-card-{{ $loop->index }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-zinc-950 dark:text-white">
                                    {{ $row['invoice_no'] ?? $row['party'] ?? $row['name'] ?? $row['label'] ?? $row['description'] ?? '-' }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $row['date'] ?? $row['status'] ?? $meta['title'] }}</p>
                            </div>
                            @if (isset($row['status']))
                                <flux:badge size="sm" color="zinc">{{ $row['status'] }}</flux:badge>
                            @endif
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            @foreach ($this->columns as $column)
                                <div class="min-w-0 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60">
                                    <dt class="font-semibold uppercase tracking-wider text-zinc-400">{{ __($column['label']) }}</dt>
                                    <dd class="mt-1 truncate font-bold {{ isset($column['tone']) ? $this->toneClass($column['tone']) : 'text-zinc-800 dark:text-zinc-100' }}">
                                        {{ $this->displayValue($row, $column) }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm font-medium text-zinc-400 dark:border-zinc-800">
                        {{ __($meta['empty']) }}
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="print-only report-print-sheet">
        <header class="report-print-header">
            <div>
                <h1>{{ $this->business['name'] }}</h1>
                <p>{{ $this->business['address'] }}</p>
                <p>{{ __('Phone') }}: {{ $this->business['phone'] }}</p>
                @if ($this->business['email'])
                    <p>{{ __('Email') }}: {{ $this->business['email'] }}</p>
                @endif
            </div>
            <div class="report-print-meta">
                <h2>{{ __($meta['title']) }}</h2>
                @if ($dateSensitive)
                    <p><strong>{{ __('Period') }}:</strong> {{ $startDate }} {{ __('to') }} {{ $endDate }}</p>
                @else
                    <p><strong>{{ __('Period') }}:</strong> {{ __('Current Snapshot') }}</p>
                @endif
                <p><strong>{{ __('Method') }}:</strong> {{ $paymentMethod === 'all' ? __('All Methods') : str($paymentMethod)->replace('_', ' ')->headline() }}</p>
                <p><strong>{{ __('Filter') }}:</strong> {{ str($reportStatus)->replace('_', ' ')->headline() }}</p>
                <p><strong>{{ __('Generated') }}:</strong> {{ $this->generatedAt }}</p>
            </div>
        </header>

        <div class="report-print-summary">
            @foreach ($this->summary as $item)
                <div>
                    <span>{{ __($item['label']) }}</span>
                    <strong class="print-{{ $item['tone'] }}">{{ $item['value'] }}</strong>
                </div>
            @endforeach
        </div>

        @include('pages.reports.partials.report-table', ['print' => true])

        <footer class="report-print-signatures">
            <div><span></span><p>{{ __('Prepared By') }}</p></div>
            <div><span></span><p>{{ __('Reviewed By') }}</p></div>
            <div><span></span><p>{{ __('Authorized Signatory') }}</p></div>
        </footer>
    </section>
</div>
