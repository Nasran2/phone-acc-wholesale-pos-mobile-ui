@php
    use App\Models\Setting;

    $businessName = Setting::get('business_name', config('app.name'));
    $businessPhone = Setting::get('business_phone');
    $businessEmail = Setting::get('business_email');
    $businessAddress = Setting::get('business_address');
    $footerNote = Setting::get('invoice_footer_note') ?: __('Thank you for shopping with us. No cash refunds. Exchange valid within 7 days with invoice.');
    $terms = Setting::get('invoice_terms') ?: __('Warranty claims subject to physical inspection.');
    $currency = Setting::get('currency_symbol', 'Rs');
    $primaryPayment = $sale->payments->first();
    $paymentMethod = str_replace('_', ' ', $primaryPayment?->payment_method ?? $sale->payment_status ?? 'cash');
    $statusLabel = str((string) $sale->payment_status)->replace('_', ' ')->headline()->toString();
    $hasTax = (float) $sale->tax_amount > 0;
    $hasDiscount = (float) $sale->discount_amount > 0;
    $customerPhone = trim((string) ($sale->customer?->phone ?? ''));
    $customerAddress = trim((string) ($sale->customer?->address ?? ''));
    $hiddenValues = ['', '0', '0000000000', 'n/a', 'na', '-'];
    $customerPhone = in_array(strtolower($customerPhone), $hiddenValues, true) ? '' : $customerPhone;
    $customerAddress = in_array(strtolower($customerAddress), $hiddenValues, true) ? '' : $customerAddress;
    $businessContact = collect([$businessPhone, $businessEmail])->filter(fn ($value): bool => filled($value))->implode(' | ');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }} - {{ $businessName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased print:bg-white">
    <main class="mx-auto min-h-screen w-full max-w-5xl px-4 py-6 sm:px-6 sm:py-10 print:max-w-none print:p-0">
        <section class="relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-8 print:rounded-none print:p-8 print:shadow-none print:ring-0">
            <div class="absolute left-0 top-0 h-1.5 w-full bg-gradient-to-r from-violet-600 via-indigo-500 to-cyan-500"></div>

            <header class="flex flex-col gap-6 border-b border-slate-100 pb-6 pt-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-violet-600">{{ __('Retail Bill') }}</p>
                    <h1 class="mt-2 text-4xl font-black tracking-tight text-slate-950">{{ __('INVOICE') }}</h1>
                    <div class="mt-3 inline-flex items-center gap-2 rounded-lg border border-slate-100 bg-slate-50 px-3 py-1.5 text-sm font-semibold">
                        <span class="text-slate-400">{{ __('No') }}:</span>
                        <span>{{ $sale->invoice_no }}</span>
                    </div>
                </div>

                <div class="max-w-md sm:text-right">
                    <div class="flex items-center gap-3 sm:justify-end">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-violet-600 to-indigo-600 text-sm font-black text-white shadow-sm">
                            I
                        </span>
                        <h2 class="text-xl font-black tracking-tight">{{ $businessName }}</h2>
                    </div>
                    @if ($businessAddress)
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $businessAddress }}</p>
                    @endif
                    @if ($businessContact)
                        <p class="mt-1 text-sm font-semibold text-slate-400">{{ $businessContact }}</p>
                    @endif
                </div>
            </header>

            <section class="grid gap-6 border-b border-slate-100 py-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400">{{ __('Bill to') }}</p>
                    <h3 class="mt-2 text-xl font-bold">{{ $sale->customer?->name ?: __('Walk-in Customer') }}</h3>
                    @if ($customerAddress)
                        <p class="mt-2 text-sm text-slate-500">{{ $customerAddress }}</p>
                    @endif
                    @if ($customerPhone)
                        <p class="mt-1 text-sm font-medium text-slate-400">{{ $customerPhone }}</p>
                    @endif
                </div>

                <div class="grid grid-cols-[1fr_auto] gap-x-5 gap-y-2 text-sm sm:justify-end sm:text-right">
                    <span class="font-medium text-slate-400">{{ __('Invoice Number') }}:</span>
                    <span class="font-semibold">{{ $sale->invoice_no }}</span>
                    <span class="font-medium text-slate-400">{{ __('Date') }}:</span>
                    <span class="font-semibold">{{ $sale->date?->format('d M Y') }}</span>
                    <span class="font-medium text-slate-400">{{ __('Payment Method') }}:</span>
                    <span class="font-bold uppercase text-violet-600">{{ $paymentMethod }}</span>
                    <span class="font-medium text-slate-400">{{ __('Status') }}:</span>
                    <span @class([
                        'font-bold uppercase',
                        'text-emerald-600' => $sale->payment_status === 'paid',
                        'text-amber-600' => in_array($sale->payment_status, ['partial', 'cheque_pending'], true),
                        'text-rose-600' => $sale->payment_status === 'due',
                    ])>{{ $statusLabel }}</span>
                </div>
            </section>

            <section class="overflow-x-auto py-6">
                <table class="w-full min-w-[720px] border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b-2 border-slate-200 text-xs font-bold uppercase tracking-wide text-slate-500">
                            <th class="w-24 py-3 text-center">{{ __('Quantity') }}</th>
                            <th class="w-36 px-3 py-3">{{ __('Item #') }}</th>
                            <th class="px-3 py-3">{{ __('Description') }}</th>
                            <th class="w-36 px-3 py-3 text-right">{{ __('Unit Price') }}</th>
                            <th class="w-36 py-3 text-right">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($sale->items as $item)
                            <tr>
                                <td class="py-4 text-center font-black text-violet-600">{{ $item->quantity }}</td>
                                <td class="px-3 py-4 font-mono text-xs text-slate-500">{{ $item->product?->sku ?: $item->product_id }}</td>
                                <td class="px-3 py-4 font-semibold">{{ $item->product?->name ?? __('Item') }}</td>
                                <td class="px-3 py-4 text-right text-slate-600">{{ $currency }} {{ number_format((float) $item->selling_price, 2) }}</td>
                                <td class="py-4 text-right font-bold">{{ $currency }} {{ number_format((float) $item->subtotal, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="grid gap-6 sm:grid-cols-[1fr_22rem]">
                <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Notes & Remarks') }}</p>
                    <p class="mt-3 text-sm leading-6 text-slate-500">{{ $footerNote }}</p>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-100 bg-slate-50/30 text-sm font-semibold">
                    <div class="grid grid-cols-2 border-b border-slate-100 px-4 py-3">
                        <span class="text-slate-400">{{ __('Subtotal') }}</span>
                        <span class="text-right">{{ $currency }} {{ number_format((float) $sale->subtotal_amount, 2) }}</span>
                    </div>
                    @if ($hasDiscount)
                        <div class="grid grid-cols-2 border-b border-slate-100 bg-rose-50/50 px-4 py-3 text-rose-600">
                            <span>{{ __('Discount') }}</span>
                            <span class="text-right">- {{ $currency }} {{ number_format((float) $sale->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    @if ($hasTax)
                        <div class="grid grid-cols-2 border-b border-slate-100 px-4 py-3">
                            <span class="text-slate-400">{{ __('Tax') }}</span>
                            <span class="text-right">{{ $currency }} {{ number_format((float) $sale->tax_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-3 font-black text-white">
                        <span>{{ __('TOTAL') }}</span>
                        <span class="text-right">{{ $currency }} {{ number_format((float) $sale->grand_total, 2) }}</span>
                    </div>
                    @foreach ($sale->payments as $payment)
                        <div class="grid grid-cols-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
                            <span>{{ __('Payment') }} ({{ $payment->date?->format('d M Y') }})</span>
                            <span class="text-right">{{ $currency }} {{ number_format((float) $payment->amount, 2) }}</span>
                        </div>
                    @endforeach
                    <div class="grid grid-cols-2 border-b border-slate-100 bg-white px-4 py-3">
                        <span class="text-slate-500">{{ __('Total Paid') }}</span>
                        <span class="text-right font-black text-emerald-600">{{ $currency }} {{ number_format((float) $sale->paid_amount, 2) }}</span>
                    </div>
                    <div @class([
                        'grid grid-cols-2 px-4 py-3 font-black',
                        'bg-rose-50 text-rose-600' => (float) $sale->due_amount > 0,
                        'bg-slate-50 text-slate-400' => (float) $sale->due_amount <= 0,
                    ])>
                        <span>{{ __('Due Balance') }}</span>
                        <span class="text-right">{{ $currency }} {{ number_format((float) $sale->due_amount, 2) }}</span>
                    </div>
                </div>
            </section>

            <section class="mt-8 rounded-xl border border-violet-100 bg-violet-50/40 p-5">
                <p class="text-xs font-bold uppercase tracking-wide text-violet-700">{{ __('Terms & Conditions') }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $terms }}</p>
            </section>

            <footer class="mt-12 flex flex-col gap-8 text-center text-xs font-medium text-slate-400 sm:grid sm:grid-cols-3">
                <div>
                    <div class="h-px bg-slate-200"></div>
                    <p class="mt-3">{{ __('Authorized Signature') }}</p>
                </div>
                <div>
                    <div class="h-px bg-slate-200"></div>
                    <p class="mt-3">{{ __('Customer Signature') }}</p>
                </div>
                <div>
                    <div class="h-px bg-slate-200"></div>
                    <p class="mt-3">{{ __('Date') }}</p>
                </div>
            </footer>
        </section>
    </main>
</body>
</html>
