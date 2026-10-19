@php
    use App\Models\Setting;
    use Illuminate\Support\Facades\Storage;

    $businessName = Setting::get('business_name') ?: config('app.name', 'Invoice');
    $businessLogo = Setting::get('business_logo');
    $showBusinessLogo = Setting::get('invoice_show_logo', '1') !== '0';
    $businessLogoUrl = ($showBusinessLogo && $businessLogo) ? Storage::url($businessLogo) : null;
    $businessAddress = Setting::get('business_address');
    $businessPhone = Setting::get('business_phone');
    $businessPhone2 = Setting::get('business_phone_2');
    $businessBrNumber = Setting::get('business_br_number');
    $businessEmail = Setting::get('business_email');
    $footerNote = Setting::get('invoice_footer_note');
    $terms = Setting::get('invoice_terms') ?: __('Goods once sold are not refundable except under approved return policy.');
    $currency = Setting::get('currency_symbol', 'Rs');
    $primaryPayment = $sale->payments->first();
    $paymentMethod = str_replace('_', ' ', $primaryPayment?->payment_method ?? $sale->payment_status ?? 'cash');
    $blankRows = max(0, 3 - $sale->items->count());
    $statusLabel = str((string) $sale->payment_status)->replace('_', ' ')->headline()->toString();
    $hasTax = (float) $sale->tax_amount > 0;
    $hasDiscount = (float) $sale->discount_amount > 0;
    $customerPhone = trim((string) ($sale->customer?->phone ?? ''));
    $customerAddress = trim((string) ($sale->customer?->address ?? ''));
    $hiddenValues = ['', '0', '0000000000', 'n/a', 'na', '-'];
    $customerPhone = in_array(strtolower($customerPhone), $hiddenValues, true) ? '' : $customerPhone;
    $customerAddress = in_array(strtolower($customerAddress), $hiddenValues, true) ? '' : $customerAddress;

    $businessContact = collect([$businessPhone, $businessPhone2, $businessEmail])
        ->filter(fn ($value): bool => filled($value))
        ->implode('  |  ');
@endphp

<div id="a4-invoice-template" class="hidden bg-white text-slate-900 print:block">
    <style>
        @media print {
            html, body {
                width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 !important;
                overflow: visible !important;
                background: white !important;
            }

            body * {
                visibility: hidden !important;
            }

            #a4-invoice-template, #a4-invoice-template * {
                visibility: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            #a4-invoice-template {
                display: block !important;
                position: absolute !important;
                left: 0 !important;
                right: 0 !important;
                top: 0 !important;
                width: 100% !important;
                height: auto !important;
                min-height: 100% !important;
                margin: 0 auto !important;
                padding: 0 !important;
                background: white !important;
                overflow: visible !important;
                z-index: 9999999 !important;
            }

            @page {
                size: A4;
                margin: 8mm;
            }
        }
    </style>

    <div class="relative mx-auto flex min-h-[281mm] w-[194mm] flex-col overflow-visible bg-white p-[8mm] text-slate-800">
        <!-- Top accent gradient line -->
        <div class="absolute left-0 top-0 h-1.5 w-full bg-gradient-to-r from-violet-600 via-indigo-500 to-cyan-500"></div>

        <!-- Header -->
        <header class="flex items-start justify-between gap-8 border-b border-slate-100 pb-5 pt-2">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-violet-600">{{ __('Retail Bill') }}</p>
                <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-900">{{ __('INVOICE') }}</h1>
                <div class="mt-2.5 inline-flex items-center gap-1.5 rounded-md bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 border border-slate-100">
                    <span class="text-slate-400">No:</span>
                    <span class="font-bold text-slate-900">{{ $sale->invoice_no }}</span>
                </div>
            </div>

            <div class="max-w-[78mm] text-right">
                <div class="flex items-start justify-end gap-2.5">
                    <div class="shrink-0 overflow-hidden rounded-md border border-slate-100 bg-white" style="display: flex; width: 18mm !important; height: 14mm !important; align-items: center; justify-content: center;">
                        @if ($businessLogoUrl)
                            <img src="{{ $businessLogoUrl }}" alt="{{ $businessName }}" style="display: block; width: 100% !important; height: 100% !important; max-width: 100% !important; max-height: 100% !important; object-fit: contain !important;">
                        @else
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-gradient-to-br from-violet-600 to-indigo-600 text-white text-xs font-bold shadow-sm shadow-indigo-100">
                                I
                            </span>
                        @endif
                    </div>
                    <span class="max-w-[48mm] text-lg font-black leading-tight tracking-tight text-slate-900">{{ $businessName }}</span>
                </div>
                @if ($businessAddress)
                    <p class="mt-1.5 text-[11px] leading-relaxed text-slate-500">{{ $businessAddress }}</p>
                @endif
                @if ($businessContact)
                    <p class="mt-0.5 text-[10px] font-semibold text-slate-400">{{ $businessContact }}</p>
                @endif
                @if ($businessBrNumber)
                    <p class="mt-0.5 text-[10px] font-semibold text-slate-400">{{ __('BR No') }}: {{ $businessBrNumber }}</p>
                @endif
            </div>
        </header>

        <!-- Bill To / Invoice Details Info Section -->
        <section class="mt-5 grid grid-cols-2 gap-8 border-b border-slate-100 pb-5">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Bill to') }}</p>
                <h2 class="mt-1.5 text-base font-bold text-slate-900">{{ $sale->customer?->name ?: __('Walk-in Customer') }}</h2>
                
                @if ($customerAddress || $customerPhone)
                    <div class="mt-1.5 space-y-0.5 text-[11px] text-slate-500">
                        @if ($customerAddress)
                            <p class="leading-relaxed">{{ $customerAddress }}</p>
                        @endif
                        @if ($customerPhone)
                            <p class="font-medium text-slate-400">{{ $customerPhone }}</p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex justify-end">
                <div class="inline-grid grid-cols-2 gap-x-4 gap-y-1.5 text-right text-xs">
                    <span class="text-slate-400 font-medium">{{ __('Invoice Number') }}:</span>
                    <span class="font-semibold text-slate-900">{{ $sale->invoice_no }}</span>

                    <span class="text-slate-400 font-medium">{{ __('Date') }}:</span>
                    <span class="font-semibold text-slate-900">{{ $sale->date?->format('d M Y') }}</span>
                    
                    <span class="text-slate-400 font-medium">{{ __('Payment Method') }}:</span>
                    <span class="font-bold text-violet-600 uppercase">{{ $paymentMethod }}</span>

                    <span class="text-slate-400 font-medium">{{ __('Status') }}:</span>
                    <span @class([
                        'font-bold uppercase',
                        'text-emerald-600' => $sale->payment_status === 'paid',
                        'text-amber-600' => in_array($sale->payment_status, ['partial', 'cheque_pending'], true),
                        'text-rose-600' => $sale->payment_status === 'due',
                    ])>{{ $statusLabel }}</span>
                </div>
            </div>
        </section>

        <!-- Table of Items -->
        <section class="mt-5">
            <table class="w-full border-collapse text-left text-xs">
                <thead>
                    <tr class="border-b-2 border-slate-200 text-slate-500 font-semibold">
                        <th class="w-[18mm] py-2.5 text-center uppercase tracking-wider text-[10px]">Quantity</th>
                        <th class="w-[18mm] px-2 py-2.5 text-center uppercase tracking-wider text-[10px]">Type</th>
                        <th class="w-[28mm] px-2 py-2.5 uppercase tracking-wider text-[10px]">Item #</th>
                        <th class="px-2 py-2.5 uppercase tracking-wider text-[10px]">Description</th>
                        <th class="w-[28mm] px-2 py-2.5 text-right uppercase tracking-wider text-[10px]">Unit Price</th>
                        <th class="w-[28mm] py-2.5 text-right uppercase tracking-wider text-[10px]">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @foreach ($sale->items as $item)
                        @php($isReturnItem = (int) $item->quantity < 0 || (float) $item->subtotal < 0)
                        <tr @class(['h-8', 'bg-rose-50/40' => $isReturnItem])>
                            <td @class([
                                'py-2 text-center font-bold',
                                'text-rose-600' => $isReturnItem,
                                'text-violet-600' => ! $isReturnItem,
                            ])>{{ $item->quantity }}</td>
                            <td class="px-2 py-2 text-center">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wide',
                                    'bg-rose-100 text-rose-700' => $isReturnItem,
                                    'bg-violet-50 text-violet-600' => ! $isReturnItem,
                                ])>{{ $isReturnItem ? __('Return') : __('Sale') }}</span>
                            </td>
                            <td @class([
                                'px-2 py-2 font-mono text-[10px]',
                                'text-rose-600' => $isReturnItem,
                                'text-slate-500' => ! $isReturnItem,
                            ])>{{ $item->product?->sku ?: $item->product_id }}</td>
                            <td @class([
                                'px-2 py-2 font-medium',
                                'text-rose-700' => $isReturnItem,
                                'text-slate-800' => ! $isReturnItem,
                            ])>{{ $item->product?->name }}</td>
                            <td @class([
                                'px-2 py-2 text-right',
                                'text-rose-600' => $isReturnItem,
                            ])>{{ $currency }} {{ number_format($item->selling_price, 2) }}</td>
                            <td @class([
                                'py-2 text-right font-semibold',
                                'text-rose-700' => $isReturnItem,
                                'text-slate-900' => ! $isReturnItem,
                            ])>{{ $currency }} {{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                    @endforeach

                    @for ($row = 0; $row < $blankRows; $row++)
                        <tr class="h-8">
                            <td class="py-2">&nbsp;</td>
                            <td class="px-2 py-2">&nbsp;</td>
                            <td class="px-2 py-2">&nbsp;</td>
                            <td class="px-2 py-2">&nbsp;</td>
                            <td class="px-2 py-2">&nbsp;</td>
                            <td class="py-2 text-right">&nbsp;</td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </section>

        <!-- Summary and Notes -->
        <section class="mt-5 grid grid-cols-[1fr_70mm] items-start gap-8">
            <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-3.5 text-xs">
                <span class="font-bold uppercase tracking-wider text-[10px] text-slate-400">Notes & Remarks</span>
                @if ($footerNote)
                    <p class="mt-1.5 leading-relaxed text-slate-500 text-[11px]">{{ $footerNote }}</p>
                @else
                    <p class="mt-1.5 leading-relaxed text-slate-500 text-[11px]">{{ __('Thank you for shopping with us. No cash refunds. Exchange valid within 7 days with invoice.') }}</p>
                @endif
            </div>

            <div class="rounded-lg border border-slate-100 bg-slate-50/20 text-xs font-semibold overflow-hidden">
                <div class="grid grid-cols-2 px-3 py-2 border-b border-slate-100">
                    <div class="text-slate-400">Subtotal</div>
                    <div class="text-right text-slate-700">{{ $currency }} {{ number_format($sale->subtotal_amount, 2) }}</div>
                </div>
                @if ($hasDiscount)
                    <div class="grid grid-cols-2 px-3 py-2 border-b border-slate-100 text-rose-600 bg-rose-50/20">
                        <div>Discount</div>
                        <div class="text-right">- {{ $currency }} {{ number_format($sale->discount_amount, 2) }}</div>
                    </div>
                @endif
                @if ($hasTax)
                    <div class="grid grid-cols-2 px-3 py-2 border-b border-slate-100">
                        <div class="text-slate-400">Tax</div>
                        <div class="text-right text-slate-700">{{ $currency }} {{ number_format($sale->tax_amount, 2) }}</div>
                    </div>
                @endif
                <div class="grid grid-cols-2 bg-gradient-to-r from-violet-600 to-indigo-600 text-white px-3 py-2.5 font-bold">
                    <div>TOTAL</div>
                    <div class="text-right">{{ $currency }} {{ number_format($sale->grand_total, 2) }}</div>
                </div>
                @if($sale->payments->count() > 0)
                    <div class="border-t border-slate-100 bg-slate-50/50">
                        @foreach($sale->payments as $payment)
                            <div class="grid grid-cols-2 px-3 py-1.5 border-b border-slate-100/50 text-[10px] text-slate-500">
                                <div>{{ __('Payment') }} ({{ $payment->date->format('d M Y') }})</div>
                                <div class="text-right">{{ $currency }} {{ number_format($payment->amount, 2) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="grid grid-cols-2 px-3 py-2 border-t border-slate-100 font-semibold bg-white text-slate-600">
                    <div>{{ __('Total Paid') }}</div>
                    <div class="text-right text-emerald-600 font-bold">{{ $currency }} {{ number_format($sale->paid_amount, 2) }}</div>
                </div>
                <div class="grid grid-cols-2 px-3 py-2 font-bold {{ $sale->due_amount > 0 ? 'bg-rose-50 text-rose-600' : 'bg-slate-50 text-slate-400' }}">
                    <div>{{ __('Due Balance') }}</div>
                    <div class="text-right">{{ $currency }} {{ number_format($sale->due_amount, 2) }}</div>
                </div>
            </div>
        </section>

        <x-sale-return-history :sale="$sale" />

        <!-- Terms and Conditions -->
        <section class="mt-5 rounded-lg border border-violet-50 bg-violet-50/30 p-3.5">
            <h3 class="text-[10px] font-bold uppercase tracking-wider text-violet-700">Terms & Conditions</h3>
            <p class="mt-1 max-w-3xl text-[11px] leading-relaxed text-slate-600">{{ $terms }}</p>
        </section>

        <!-- Signature Lines -->
        <section class="mt-10 grid grid-cols-3 gap-8 text-[11px] text-slate-400">
            <div class="text-center">
                <div class="h-px bg-slate-200"></div>
                <p class="mt-2 font-medium">Authorized Signature</p>
            </div>
            <div class="text-center">
                <div class="h-px bg-slate-200"></div>
                <p class="mt-2 font-medium">Customer Signature</p>
            </div>
            <div class="text-center">
                <div class="h-px bg-slate-200"></div>
                <p class="mt-2 font-medium">Date</p>
            </div>
        </section>

        <!-- Footer note -->
        <footer class="mt-auto border-t border-slate-100 pt-3.5 text-center text-[10px] font-bold text-slate-400">
            @if ($devName !== '')
                Powered by {{ $devName }}
            @endif
        </footer>
    </div>
</div>
