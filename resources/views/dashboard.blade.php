@php
    use App\Models\Expense;
    use App\Models\HoldOrder;
    use App\Models\Payment;
    use App\Models\Product;
    use App\Models\Sale;
    use App\Models\SaleItem;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\DB;

    $today = Carbon::today();
    $yesterday = Carbon::yesterday();

    $todaySalesSum = (float) Sale::query()->whereDate('date', $today)->sum('grand_total');
    $yesterdaySalesSum = (float) Sale::query()->whereDate('date', $yesterday)->sum('grand_total');
    $todaySalesCount = Sale::query()->whereDate('date', $today)->count();
    $paidToday = (float) Sale::query()->whereDate('date', $today)->sum('paid_amount');
    $dueToday = (float) Sale::query()->whereDate('date', $today)->sum('due_amount');

    $todayCogs = (float) SaleItem::query()
        ->whereHas('sale', fn ($query) => $query->whereDate('date', $today))
        ->sum(DB::raw('quantity * cost_price'));

    $todayExpenses = (float) Expense::query()->whereDate('date', $today)->sum('amount');
    $todayProfit = max(0.00, $todaySalesSum - $todayCogs - $todayExpenses);

    $cashInflow = (float) Payment::query()
        ->where('payment_method', 'cash')
        ->whereHasMorph('paymentable', [\App\Models\Customer::class, \App\Models\Sale::class])
        ->sum('amount');

    $cashOutflow = (float) Payment::query()
        ->where('payment_method', 'cash')
        ->whereHasMorph('paymentable', [\App\Models\Supplier::class, \App\Models\Purchase::class])
        ->sum('amount');

    $cashExpensesOutflow = (float) Expense::query()
        ->where('payment_method', 'cash')
        ->sum('amount');

    $cashBalance = max(0.00, $cashInflow - $cashOutflow - $cashExpensesOutflow);
    $lowStockCount = Product::query()->where('stock_quantity', '<=', DB::raw('minimum_stock'))->count();
    $lowStockProducts = Product::query()
        ->with(['category'])
        ->where('stock_quantity', '<=', DB::raw('minimum_stock'))
        ->orderBy('stock_quantity', 'asc')
        ->limit(10)
        ->get();
    $totalProducts = Product::query()->count();
    $inventoryValue = (float) Product::query()->sum(DB::raw('stock_quantity * cost_price'));
    $holdOrdersCount = HoldOrder::query()->count();
    $recentSales = Sale::query()->with('customer')->latest('id')->limit(5)->get();

    $salesChange = $yesterdaySalesSum > 0
        ? (($todaySalesSum - $yesterdaySalesSum) / $yesterdaySalesSum) * 100
        : ($todaySalesSum > 0 ? 100 : 0);

    $salesTrend = collect(range(6, 0))->map(function (int $daysBack) use ($today): array {
        $date = $today->copy()->subDays($daysBack);

        return [
            'label' => $date->format('D'),
            'total' => (float) Sale::query()->whereDate('date', $date)->sum('grand_total'),
        ];
    });

    $hasTrendSales = $salesTrend->sum('total') > 0;
    $visualTrend = $hasTrendSales
        ? $salesTrend
        : collect([
            ['label' => 'Pulse', 'total' => 36],
            ['label' => 'Pulse', 'total' => 58],
            ['label' => 'Pulse', 'total' => 42],
            ['label' => 'Pulse', 'total' => 64],
            ['label' => 'Pulse', 'total' => 46],
            ['label' => 'Pulse', 'total' => 72],
            ['label' => 'Pulse', 'total' => 55],
        ]);

    $maxTrend = max((float) $visualTrend->max('total'), 1);
    $trendPoints = $visualTrend->values()
        ->map(fn (array $day, int $index) => ($index * (100 / max($salesTrend->count() - 1, 1))).','.(62 - (($day['total'] / $maxTrend) * 50)))
        ->implode(' ');
    $trendAreaPoints = '0,68 '.$trendPoints.' 100,68';

    $paidRatio = $todaySalesSum > 0 ? min(100, round(($paidToday / $todaySalesSum) * 100)) : 0;
    $dueRatio = $todaySalesSum > 0 ? min(100, round(($dueToday / $todaySalesSum) * 100)) : 0;
    $profitRatio = $todaySalesSum > 0 ? min(100, round(($todayProfit / $todaySalesSum) * 100)) : 0;
    $stockPressure = $totalProducts > 0 ? min(100, round(($lowStockCount / $totalProducts) * 100)) : 0;

    $paymentMix = Payment::query()
        ->select('payment_method', DB::raw('SUM(amount) as total'))
        ->groupBy('payment_method')
        ->orderByDesc('total')
        ->limit(4)
        ->get();
    $paymentMixTotal = max((float) $paymentMix->sum('total'), 1);
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex flex-col gap-4 sm:gap-6">
        <section class="relative overflow-hidden rounded-3xl border border-white/70 bg-white px-4 py-5 shadow-[0_20px_70px_rgba(15,23,42,0.08)] sm:px-6 lg:px-8 dark:border-zinc-800/80 dark:bg-zinc-900">
            <div class="absolute inset-0 bg-[linear-gradient(115deg,rgba(124,58,237,0.18),rgba(6,182,212,0.12),rgba(16,185,129,0.10))] dark:bg-[linear-gradient(115deg,rgba(124,58,237,0.22),rgba(6,182,212,0.14),rgba(16,185,129,0.10))]"></div>

            <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-violet-200 bg-white/80 px-3 py-1 text-[11px] font-black uppercase tracking-wider text-violet-700 shadow-sm dark:border-violet-900/60 dark:bg-zinc-950/70 dark:text-violet-300">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_18px_rgba(52,211,153,0.9)]"></span>
                        {{ __('Live retail command') }}
                    </div>
                    <h1 class="mt-4 font-display text-2xl font-bold tracking-tight text-zinc-950 sm:text-4xl dark:text-white">
                        {{ __('Phone accessory performance cockpit') }}
                    </h1>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                        {{ __('Sales, cash drawer, stock pressure, and checkout flow shaped for fast mobile scanning.') }}
                    </p>
                </div>

                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                    <a href="{{ route('pos.index') }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 text-xs font-black text-white shadow-[0_14px_35px_rgba(15,23,42,0.25)] transition active:scale-95 dark:bg-white dark:text-zinc-950">
                        <flux:icon.shopping-bag class="size-4" />
                        {{ __('POS') }}
                    </a>
                    <a href="{{ route('reports.index') }}" wire:navigate class="inline-flex h-11 items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white/80 px-4 text-xs font-black text-zinc-700 shadow-sm transition active:scale-95 dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">
                        <flux:icon.chart-bar class="size-4 text-cyan-500" />
                        {{ __('Reports') }}
                    </a>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-100 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300">
                        <flux:icon.currency-dollar class="size-5" />
                    </div>
                    <span @class([
                        'rounded-full px-2.5 py-1 text-[11px] font-black',
                        'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300' => $salesChange >= 0,
                        'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300' => $salesChange < 0,
                    ])>
                        {{ $salesChange >= 0 ? '+' : '' }}{{ number_format($salesChange, 1) }}%
                    </span>
                </div>
                <p class="mt-5 text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('Today sales') }}</p>
                <p class="mt-1 font-display text-2xl font-bold text-zinc-950 dark:text-white">Rs {{ number_format($todaySalesSum, 2) }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice('{0} No invoices today|{1} :count invoice today|[2,*] :count invoices today', $todaySalesCount, ['count' => $todaySalesCount]) }}</p>
            </div>

            <div class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300">
                        <flux:icon.arrow-trending-up class="size-5" />
                    </div>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-black text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300">{{ $profitRatio }}%</span>
                </div>
                <p class="mt-5 text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('Today profit') }}</p>
                <p class="mt-1 font-display text-2xl font-bold text-zinc-950 dark:text-white">Rs {{ number_format($todayProfit, 2) }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('After cost and daily expenses.') }}</p>
            </div>

            <div class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-cyan-100 text-cyan-600 dark:bg-cyan-950/50 dark:text-cyan-300">
                        <flux:icon.banknotes class="size-5" />
                    </div>
                    <span class="rounded-full bg-cyan-50 px-2.5 py-1 text-[11px] font-black text-cyan-600 dark:bg-cyan-950/40 dark:text-cyan-300">{{ __('Cash') }}</span>
                </div>
                <p class="mt-5 text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('Drawer balance') }}</p>
                <p class="mt-1 font-display text-2xl font-bold text-zinc-950 dark:text-white">Rs {{ number_format($cashBalance, 2) }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Physical shop cash position.') }}</p>
            </div>

            <div class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300">
                        <flux:icon.exclamation-triangle class="size-5" />
                    </div>
                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-600 dark:bg-amber-950/40 dark:text-amber-300">{{ $stockPressure }}%</span>
                </div>
                <p class="mt-5 text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('Low stock') }}</p>
                <p class="mt-1 font-display text-2xl font-bold text-zinc-950 dark:text-white">{{ $lowStockCount }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Items need replenishment.') }}</p>
            </div>
        </section>

        <livewire:dashboard.cheque-follow-up />

        <section class="grid gap-4 xl:grid-cols-[1.4fr_0.9fr]">
            <div class="overflow-hidden rounded-3xl border border-white/70 bg-white p-4 shadow-[0_20px_70px_rgba(15,23,42,0.07)] sm:p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-violet-500">{{ __('Performance') }}</p>
                        <h2 class="font-display text-lg font-bold text-zinc-950 sm:text-xl dark:text-white">{{ __('7 day sales signal') }}</h2>
                    </div>
                    <div class="rounded-2xl bg-zinc-50 px-3 py-2 text-xs font-bold text-zinc-500 dark:bg-zinc-950/70 dark:text-zinc-300">
                        {{ $hasTrendSales ? __('Peak').' Rs '.number_format($maxTrend, 2) : __('Ready for sales') }}
                    </div>
                </div>

                <div class="mt-5 h-56 rounded-3xl bg-[radial-gradient(circle_at_top,rgba(124,58,237,0.10),transparent_34%),linear-gradient(180deg,rgba(250,250,250,1),rgba(244,244,245,0.55))] p-3 dark:bg-[radial-gradient(circle_at_top,rgba(124,58,237,0.18),transparent_36%),linear-gradient(180deg,rgba(24,24,27,1),rgba(10,10,10,0.65))]">
                    <svg class="h-full w-full overflow-visible" viewBox="0 0 100 72" preserveAspectRatio="none" role="img" aria-label="{{ __('Seven day sales trend') }}">
                        <defs>
                            <linearGradient id="dashboardTrendLine" x1="0" x2="1" y1="0" y2="0">
                                <stop offset="0%" stop-color="#7c3aed" />
                                <stop offset="55%" stop-color="#06b6d4" />
                                <stop offset="100%" stop-color="#10b981" />
                            </linearGradient>
                            <linearGradient id="dashboardTrendArea" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#7c3aed" stop-opacity="0.28" />
                                <stop offset="100%" stop-color="#7c3aed" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <polygon points="{{ $trendAreaPoints }}" fill="url(#dashboardTrendArea)" />
                        <polyline points="{{ $trendPoints }}" fill="none" stroke="url(#dashboardTrendLine)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        @foreach ($salesTrend->values() as $index => $day)
                            @php
                                $x = $index * (100 / max($salesTrend->count() - 1, 1));
                                $visualTotal = $visualTrend->values()[$index]['total'];
                                $y = 62 - (($visualTotal / $maxTrend) * 50);
                            @endphp
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="1.8" fill="#ffffff" stroke="#7c3aed" stroke-width="1.8" vector-effect="non-scaling-stroke" />
                        @endforeach
                    </svg>
                </div>

                <div class="mt-3 grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase tracking-wide text-zinc-400">
                    @foreach ($salesTrend as $day)
                        <span>{{ $day['label'] }}</span>
                    @endforeach
                </div>
            </div>

            <div class="rounded-3xl border border-white/70 bg-zinc-950 p-5 text-white shadow-[0_20px_70px_rgba(15,23,42,0.18)] dark:border-zinc-800">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-cyan-300">{{ __('Checkout health') }}</p>
                        <h2 class="font-display text-lg font-bold">{{ __('Collections by status') }}</h2>
                    </div>
                    <a href="{{ route('sales.index') }}" wire:navigate class="rounded-2xl bg-white/10 px-3 py-2 text-xs font-black text-white transition hover:bg-white/15">
                        {{ __('View') }}
                    </a>
                </div>

                <div class="mt-6 grid place-items-center">
                    <div class="relative h-44 w-44">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 42 42" role="img" aria-label="{{ __('Paid due and profit ratios') }}">
                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="rgba(255,255,255,0.08)" stroke-width="5" />
                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#8b5cf6" stroke-width="5" stroke-dasharray="{{ $paidRatio }} 100" stroke-linecap="round" />
                            <circle cx="21" cy="21" r="10.915" fill="transparent" stroke="rgba(255,255,255,0.08)" stroke-width="4" />
                            <circle cx="21" cy="21" r="10.915" fill="transparent" stroke="#06b6d4" stroke-width="4" stroke-dasharray="{{ $profitRatio }} 100" stroke-linecap="round" />
                            <circle cx="21" cy="21" r="6.915" fill="transparent" stroke="rgba(255,255,255,0.08)" stroke-width="3" />
                            <circle cx="21" cy="21" r="6.915" fill="transparent" stroke="#fb7185" stroke-width="3" stroke-dasharray="{{ $dueRatio }} 100" stroke-linecap="round" />
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div>
                                <p class="font-display text-3xl font-bold">{{ $paidRatio }}%</p>
                                <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Paid') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3">
                    <div class="flex items-center justify-between rounded-2xl bg-white/10 px-3 py-2">
                        <span class="flex items-center gap-2 text-xs font-bold text-zinc-300"><span class="h-2.5 w-2.5 rounded-full bg-violet-400"></span>{{ __('Collected') }}</span>
                        <span class="text-sm font-black">Rs {{ number_format($paidToday, 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl bg-white/10 px-3 py-2">
                        <span class="flex items-center gap-2 text-xs font-bold text-zinc-300"><span class="h-2.5 w-2.5 rounded-full bg-cyan-400"></span>{{ __('Profit') }}</span>
                        <span class="text-sm font-black">Rs {{ number_format($todayProfit, 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl bg-white/10 px-3 py-2">
                        <span class="flex items-center gap-2 text-xs font-bold text-zinc-300"><span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>{{ __('Due') }}</span>
                        <span class="text-sm font-black">Rs {{ number_format($dueToday, 2) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
            <div class="rounded-3xl border border-white/70 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-emerald-500">{{ __('Inventory pulse') }}</p>
                        <h2 class="font-display text-lg font-bold text-zinc-950 dark:text-white">{{ __('Stock value radar') }}</h2>
                    </div>
                    <span class="rounded-2xl bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">{{ $totalProducts }} {{ __('SKUs') }}</span>
                </div>

                <div class="mt-6 grid grid-cols-[1fr_auto] items-center gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-zinc-400">{{ __('Inventory at cost') }}</p>
                        <p class="mt-1 font-display text-3xl font-bold text-zinc-950 dark:text-white">Rs {{ number_format($inventoryValue, 2) }}</p>
                        <div class="mt-5 grid gap-3">
                            <div>
                                <div class="flex justify-between text-xs font-bold text-zinc-500 dark:text-zinc-400">
                                    <span>{{ __('Healthy stock') }}</span>
                                    <span>{{ max(0, 100 - $stockPressure) }}%</span>
                                </div>
                                <div class="mt-2 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-2 rounded-full bg-emerald-400" style="width: {{ max(0, 100 - $stockPressure) }}%"></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs font-bold text-zinc-500 dark:text-zinc-400">
                                    <span>{{ __('Restock pressure') }}</span>
                                    <span>{{ $stockPressure }}%</span>
                                </div>
                                <div class="mt-2 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-2 rounded-full bg-amber-400" style="width: {{ $stockPressure }}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="relative h-28 w-28">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 42 42" role="img" aria-label="{{ __('Inventory risk ratio') }}">
                            <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="#10b981" stroke-width="5" stroke-dasharray="{{ max(0, 100 - $stockPressure) }} 100" stroke-linecap="round" />
                            <circle cx="21" cy="21" r="10.915" fill="transparent" stroke="#f59e0b" stroke-width="4" stroke-dasharray="{{ $stockPressure }} 100" stroke-linecap="round" />
                        </svg>
                        <div class="absolute inset-2 grid place-items-center rounded-full bg-white text-center shadow-[0_16px_35px_rgba(16,185,129,0.16)] dark:bg-zinc-900">
                            <div>
                                <p class="font-display text-xl font-bold text-zinc-950 dark:text-white">{{ $stockPressure }}%</p>
                                <p class="text-[9px] font-black uppercase tracking-wider text-zinc-400">{{ __('Risk') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-white/70 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-cyan-500">{{ __('Payment lanes') }}</p>
                        <h2 class="font-display text-lg font-bold text-zinc-950 dark:text-white">{{ __('Tender distribution') }}</h2>
                    </div>
                    <span class="rounded-2xl bg-zinc-50 px-3 py-2 text-xs font-black text-zinc-500 dark:bg-zinc-950/70 dark:text-zinc-300">{{ __('All time') }}</span>
                </div>

                <div class="mt-5 grid gap-4">
                    @forelse ($paymentMix as $index => $payment)
                        @php
                            $paymentPercent = min(100, round(((float) $payment->total / $paymentMixTotal) * 100));
                            $laneColors = ['bg-violet-500', 'bg-cyan-500', 'bg-emerald-500', 'bg-rose-500'];
                        @endphp
                        <div class="grid gap-2">
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-sm font-bold capitalize text-zinc-700 dark:text-zinc-200">{{ str_replace('_', ' ', $payment->payment_method) }}</span>
                                <span class="text-xs font-black text-zinc-500 dark:text-zinc-400">Rs {{ number_format((float) $payment->total, 2) }}</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-2.5 rounded-full {{ $laneColors[$index] ?? 'bg-zinc-500' }}" style="width: {{ $paymentPercent }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-200 p-5 text-center text-sm font-bold text-zinc-400 dark:border-zinc-800">
                            {{ __('No payment lanes recorded yet.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-3xl border border-white/70 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-violet-500">{{ __('Recent transactions') }}</p>
                        <h2 class="font-display text-lg font-bold text-zinc-950 dark:text-white">{{ __('Live receipt stream') }}</h2>
                    </div>
                    <a href="{{ route('sales.index') }}" wire:navigate class="rounded-2xl border border-zinc-200 px-3 py-2 text-xs font-black text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-950">
                        {{ __('All') }}
                    </a>
                </div>

                <div class="mt-5 grid gap-3">
                    @forelse ($recentSales as $sale)
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-zinc-100 bg-zinc-50/70 p-3 dark:border-zinc-800 dark:bg-zinc-950/40">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-violet-600 shadow-sm dark:bg-zinc-900 dark:text-violet-300">
                                    <flux:icon.receipt-percent class="size-5" />
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $sale->invoice_no }}</p>
                                        @if ($sale->payment_status === 'paid')
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300">{{ __('Paid') }}</span>
                                        @else
                                            <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-black text-rose-600 dark:bg-rose-950/40 dark:text-rose-300">{{ __('Due') }}</span>
                                        @endif
                                    </div>
                                    <p class="truncate text-xs font-semibold text-zinc-400">{{ $sale->customer?->name ?? __('Walk-in customer') }}</p>
                                </div>
                            </div>
                            <p class="shrink-0 text-sm font-black text-zinc-950 dark:text-white">Rs {{ number_format((float) $sale->grand_total, 2) }}</p>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-200 p-6 text-center text-sm font-bold text-zinc-400 dark:border-zinc-800">
                            {{ __('No retail transactions logged yet.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="grid gap-4">
                <div class="rounded-3xl border border-white/70 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-black uppercase tracking-wider text-amber-500">{{ __('Held register') }}</p>
                            <h2 class="font-display text-lg font-bold text-zinc-950 dark:text-white">{{ __('Paused checkouts') }}</h2>
                        </div>
                        <p class="font-display text-5xl font-bold text-amber-500">{{ $holdOrdersCount }}</p>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-zinc-500 dark:text-zinc-400">{{ __('Pending sessions waiting to be resumed at the POS terminal.') }}</p>
                    <a href="{{ route('pos.index') }}" wire:navigate class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-amber-400 px-4 py-3 text-sm font-black text-zinc-950 transition active:scale-95">
                        <flux:icon.clock class="size-4" />
                        {{ __('Resume queue') }}
                    </a>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <a href="{{ route('products.create') }}" wire:navigate class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] transition active:scale-95 dark:border-zinc-800 dark:bg-zinc-900">
                        <flux:icon.plus-circle class="size-6 text-violet-500" />
                        <p class="mt-4 text-sm font-black text-zinc-950 dark:text-white">{{ __('Add product') }}</p>
                        <p class="mt-1 text-xs text-zinc-400">{{ __('Catalog') }}</p>
                    </a>
                    <a href="{{ route('expenses.index') }}" wire:navigate class="rounded-3xl border border-white/70 bg-white p-4 shadow-[0_18px_45px_rgba(15,23,42,0.06)] transition active:scale-95 dark:border-zinc-800 dark:bg-zinc-900">
                        <flux:icon.minus-circle class="size-6 text-rose-500" />
                        <p class="mt-4 text-sm font-black text-zinc-950 dark:text-white">{{ __('Add expense') }}</p>
                        <p class="mt-1 text-xs text-zinc-400">{{ __('Ledger') }}</p>
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-white/70 bg-white p-5 shadow-[0_18px_45px_rgba(15,23,42,0.06)] dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-black uppercase tracking-wider text-rose-500">{{ __('Inventory alert') }}</p>
                    <h2 class="font-display text-lg font-bold text-zinc-950 dark:text-white">{{ __('Critical Stock Alerts') }}</h2>
                </div>
                <span class="rounded-2xl bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                    {{ $lowStockCount }} {{ trans_choice('{0} products need attention|{1} product needs attention|[2,*] products need attention', $lowStockCount) }}
                </span>
            </div>

            @if($lowStockProducts->isNotEmpty())
                <!-- Desktop & Tablet View -->
                <div class="hidden md:block mt-5 overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-zinc-100 text-xs font-bold uppercase tracking-wider text-zinc-400 dark:border-zinc-800">
                                <th class="py-3 px-4">{{ __('Product') }}</th>
                                <th class="py-3 px-4">{{ __('SKU') }}</th>
                                <th class="py-3 px-4 text-right">{{ __('Current Stock') }}</th>
                                <th class="py-3 px-4 text-right">{{ __('Alert Level') }}</th>
                                <th class="py-3 px-4 text-center">{{ __('Status') }}</th>
                                <th class="py-3 px-4">{{ __('Stock Level') }}</th>
                                <th class="py-3 px-4 text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($lowStockProducts as $product)
                                <tr class="group text-sm hover:bg-zinc-50/50 dark:hover:bg-zinc-950/20">
                                    <td class="py-3.5 px-4 font-semibold text-zinc-900 dark:text-white">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-violet-50 dark:bg-violet-950/30">
                                                @if ($product->image_path)
                                                    <img
                                                        src="{{ Storage::url($product->image_path) }}"
                                                        alt="{{ $product->name }}"
                                                        class="h-full w-full object-cover"
                                                    />
                                                @else
                                                    <span class="font-display text-sm font-semibold text-violet-600 dark:text-violet-400">
                                                        {{ str($product->name)->substr(0, 1)->upper() }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div>
                                                <span class="block max-w-xs truncate font-bold">{{ $product->name }}</span>
                                                <span class="block text-xs font-normal text-zinc-400 dark:text-zinc-500">
                                                    {{ $product->category?->name ?? __('Uncategorized') }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-zinc-500 dark:text-zinc-400 font-mono text-xs">
                                        {{ $product->sku }}
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <span @class([
                                            'font-black px-2.5 py-1 rounded-full text-xs',
                                            'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300' => $product->stock_quantity <= 0,
                                            'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300' => $product->stock_quantity > 0 && $product->stock_quantity <= $product->minimum_stock,
                                        ])>
                                            {{ $product->stock_quantity }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 text-right text-zinc-500 dark:text-zinc-400 font-bold">
                                        {{ $product->minimum_stock }}
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <span @class([
                                            'text-[10px] font-black uppercase tracking-wider rounded-full px-2 py-0.5',
                                            'bg-rose-100/60 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' => $product->stock_quantity <= 0,
                                            'bg-amber-100/60 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' => $product->stock_quantity > 0 && $product->stock_quantity <= $product->minimum_stock,
                                        ])>
                                            {{ $product->stock_quantity <= 0 ? __('Out of Stock') : __('Low Stock') }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 w-44">
                                        @php
                                            $minStock = max(1, $product->minimum_stock);
                                            $percentage = min(100, max(0, round(($product->stock_quantity / $minStock) * 100)));
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                <div @class([
                                                    'h-2 rounded-full',
                                                    'bg-rose-500' => $product->stock_quantity <= 0,
                                                    'bg-amber-500' => $product->stock_quantity > 0 && $product->stock_quantity <= $product->minimum_stock,
                                                ]) style="width: {{ $percentage }}%"></div>
                                            </div>
                                            <span class="text-xs font-semibold text-zinc-500">{{ $percentage }}%</span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <flux:button variant="ghost" size="sm" :href="route('purchases.create', ['product_id' => $product->id])" wire:navigate>
                                            {{ __('Restock') }}
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Mobile View -->
                <div class="md:hidden mt-4 grid gap-3">
                    @foreach ($lowStockProducts as $product)
                        <div class="rounded-2xl border border-zinc-100 bg-zinc-50/70 p-3.5 dark:border-zinc-800 dark:bg-zinc-950/40">
                            <div class="flex items-center gap-3">
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-violet-50 dark:bg-violet-950/30">
                                    @if ($product->image_path)
                                        <img
                                            src="{{ Storage::url($product->image_path) }}"
                                            alt="{{ $product->name }}"
                                            class="h-full w-full object-cover"
                                        />
                                    @else
                                        <span class="font-display text-sm font-semibold text-violet-600 dark:text-violet-400">
                                            {{ str($product->name)->substr(0, 1)->upper() }}
                                        </span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $product->name }}</p>
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500 font-medium">SKU: {{ $product->sku }}</p>
                                </div>
                                <span @class([
                                    'text-[10px] font-black uppercase tracking-wider rounded-full px-2 py-0.5 shrink-0',
                                    'bg-rose-100/60 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' => $product->stock_quantity <= 0,
                                    'bg-amber-100/60 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' => $product->stock_quantity > 0 && $product->stock_quantity <= $product->minimum_stock,
                                ])>
                                    {{ $product->stock_quantity <= 0 ? __('Out') : __('Low') }}
                                </span>
                            </div>
                            
                            <div class="mt-4 flex items-center justify-between gap-4 border-t border-zinc-100/80 pt-3 dark:border-zinc-800/80">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    <span class="font-medium">{{ __('Stock') }}:</span>
                                    <span @class([
                                        'font-black',
                                        'text-rose-600 dark:text-rose-400' => $product->stock_quantity <= 0,
                                        'text-amber-600 dark:text-amber-400' => $product->stock_quantity > 0 && $product->stock_quantity <= $product->minimum_stock,
                                    ])>{{ $product->stock_quantity }}</span>
                                    <span class="mx-1">/</span>
                                    <span class="font-semibold">{{ $product->minimum_stock }} ({{ __('Min') }})</span>
                                </div>
                                <flux:button variant="ghost" size="sm" :href="route('purchases.create', ['product_id' => $product->id])" wire:navigate class="!py-1 !px-2.5">
                                    {{ __('Restock') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-6 rounded-2xl border border-dashed border-zinc-200 p-8 text-center text-sm font-bold text-zinc-400 dark:border-zinc-800">
                    <flux:icon.check-circle class="mx-auto size-8 text-emerald-500 mb-2" />
                    {{ __('All products are healthy. No stock alerts.') }}
                </div>
            @endif
        </section>
    </div>
</x-layouts::app>
