@php
    use App\Models\Setting;

    $businessName = Setting::get('business_name', config('app.name'));
    $businessPhone = Setting::get('business_phone');
    $businessAddress = Setting::get('business_address');
    $currency = Setting::get('currency_symbol', 'Rs');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }} - {{ $businessName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50">
    <main class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-4 px-4 py-6 sm:py-10">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800 sm:p-8">
            <div class="flex flex-col gap-4 border-b border-zinc-200 pb-5 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-xl font-bold tracking-normal">{{ $businessName }}</h1>
                    @if ($businessAddress)
                        <p class="mt-1 max-w-md text-sm text-zinc-500 dark:text-zinc-400">{{ $businessAddress }}</p>
                    @endif
                    @if ($businessPhone)
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $businessPhone }}</p>
                    @endif
                </div>

                <div class="text-left sm:text-right">
                    <p class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Invoice') }}</p>
                    <p class="text-lg font-bold">{{ $sale->invoice_no }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $sale->date?->format('Y-m-d') }}</p>
                </div>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/70">
                    <p class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</p>
                    <p class="mt-1 font-semibold">{{ $sale->customer?->name ?? __('Walk-in Customer') }}</p>
                    @if ($sale->customer?->phone)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $sale->customer->phone }}</p>
                    @endif
                    @if ($sale->customer?->address)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $sale->customer->address }}</p>
                    @endif
                </div>

                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/70">
                    <p class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ __('Payment') }}</p>
                    <p class="mt-1 font-semibold">{{ ucfirst(str_replace('_', ' ', $sale->payment_status)) }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Paid') }}: {{ $currency }} {{ number_format((float) $sale->paid_amount, 2) }}
                    </p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Due') }}: {{ $currency }} {{ number_format((float) $sale->due_amount, 2) }}
                    </p>
                </div>
            </div>

            <div class="mt-6 overflow-hidden rounded-lg ring-1 ring-zinc-200 dark:ring-zinc-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3">{{ __('Item') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Qty') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Price') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($sale->items as $item)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $item->product?->name ?? __('Item') }}</td>
                                <td class="px-4 py-3 text-right">{{ $item->quantity }}</td>
                                <td class="px-4 py-3 text-right">{{ $currency }} {{ number_format((float) $item->selling_price, 2) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $currency }} {{ number_format((float) $item->subtotal, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 ml-auto flex w-full max-w-sm flex-col gap-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                    <span>{{ $currency }} {{ number_format((float) $sale->subtotal_amount, 2) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Discount') }}</span>
                    <span>{{ $currency }} {{ number_format((float) $sale->discount_amount, 2) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Tax') }}</span>
                    <span>{{ $currency }} {{ number_format((float) $sale->tax_amount, 2) }}</span>
                </div>
                <div class="mt-2 flex justify-between border-t border-zinc-200 pt-3 text-base font-bold dark:border-zinc-800">
                    <span>{{ __('Grand Total') }}</span>
                    <span>{{ $currency }} {{ number_format((float) $sale->grand_total, 2) }}</span>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
