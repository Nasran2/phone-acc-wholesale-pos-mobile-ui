@php
    use App\Models\Setting;
    $currency = Setting::get('currency_symbol', 'Rs');
    $businessName = Setting::get('business_name', config('app.name'));
    $logo = Setting::get('business_logo');
    $logoPath = $logo ? storage_path('app/public/'.$logo) : public_path('images/twinsofte-logo.png');
    $logoData = is_file($logoPath) ? 'data:'.mime_content_type($logoPath).';base64,'.base64_encode(file_get_contents($logoPath)) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_no }}</title>
    <style>
        @page { margin: {{ $thermal ? '14px' : '32px' }}; }
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $thermal ? '9px' : '11px' }}; color: #172554; }
        h1 { font-size: {{ $thermal ? '18px' : '28px' }}; margin: 8px 0; color: #0369a1; }
        h2 { margin: 6px 0; font-size: 14px; }
        p { margin: 4px 0; }
        .header { border-bottom: 2px solid #06b6d4; padding-bottom: 12px; margin-bottom: 14px; }
        .logo { width: 48px; height: 48px; float: right; object-fit: contain; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 12px 0; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        td, th { padding: {{ $thermal ? '6px 2px' : '9px 5px' }}; border-bottom: 1px solid #dbeafe; word-wrap: break-word; }
        th { text-align: left; background: #eff6ff; color: #0369a1; }
        .number { text-align: right; }
        .total { font-weight: bold; font-size: 14px; background: #eff6ff; }
        .muted { color: #64748b; }
        .return { color: #be123c; }
        .footer { border-top: 1px solid #dbeafe; padding-top: 10px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="header">
        @if ($logoData)<img class="logo" src="{{ $logoData }}" alt="">@endif
        <h2>{{ $businessName }}</h2>
        <p>{{ Setting::get('business_address', '') }}</p>
        <p>{{ collect([Setting::get('business_phone'), Setting::get('business_phone_2')])->filter()->implode(' / ') }}</p>
        @if (Setting::get('business_br_number'))<p>BR No: {{ Setting::get('business_br_number') }}</p>@endif
        <h1>{{ __('INVOICE') }}</h1>
        <p><strong>{{ $sale->invoice_no }}</strong> &middot; {{ $sale->date?->format('d M Y') }}</p>
        <p>{{ __('Bill to') }}: {{ $sale->customer?->name ?: __('Walk-in Customer') }}</p>
        @if ($sale->customer?->phone && $sale->customer->phone !== '0000000000')<p>{{ $sale->customer->phone }}</p>@endif
        @if ($sale->customer?->address)<p>{{ $sale->customer->address }}</p>@endif
        <p>{{ __('Status') }}: {{ str($sale->payment_status)->replace('_', ' ')->headline() }}</p>
    </div>
    <table>
        <thead><tr><th style="width: 40%">{{ __('Description') }}</th><th class="number" style="width: 10%">{{ __('Qty') }}</th><th class="number" style="width: 23%">{{ __('Price') }}</th><th class="number" style="width: 27%">{{ __('Total') }}</th></tr></thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr class="{{ $item->quantity < 0 ? 'return' : '' }}">
                    <td>{{ $item->product?->name ?? __('Item') }} @if ($item->quantity < 0)({{ __('Return') }})@endif</td>
                    <td class="number">{{ $item->quantity }}</td><td class="number">{{ number_format((float) $item->selling_price, 2) }}</td><td class="number">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table>
        <tr><td>{{ __('Subtotal') }}</td><td class="number">{{ $currency }} {{ number_format((float) $sale->subtotal_amount, 2) }}</td></tr>
        @if ($sale->discount_amount > 0)<tr><td>{{ __('Discount') }}</td><td class="number">- {{ number_format((float) $sale->discount_amount, 2) }}</td></tr>@endif
        @if ($sale->tax_amount > 0)<tr><td>{{ __('Tax') }}</td><td class="number">{{ number_format((float) $sale->tax_amount, 2) }}</td></tr>@endif
        <tr class="total"><td>{{ __('TOTAL') }}</td><td class="number">{{ $currency }} {{ number_format((float) $sale->grand_total, 2) }}</td></tr>
        <tr><td>{{ __('Paid') }}</td><td class="number">{{ $currency }} {{ number_format((float) $sale->paid_amount, 2) }}</td></tr>
        <tr><td>{{ __('Due Balance') }}</td><td class="number">{{ $currency }} {{ number_format((float) $sale->due_amount, 2) }}</td></tr>
    </table>
    @foreach ($sale->payments as $payment)
        <p class="muted">{{ $payment->date?->format('d M Y') }} &middot; {{ str($payment->payment_method)->replace('_', ' ')->headline() }}: {{ $currency }} {{ number_format((float) $payment->amount, 2) }} @if ($payment->payment_method === 'cheque')({{ $payment->cheque_status }} &middot; {{ $payment->cheque_no }})@endif</p>
    @endforeach
    @foreach ($sale->returns->concat($sale->dueCredits) as $return)
        <p class="return">{{ $return->invoice_no }} &middot; {{ $return->type_label }}: {{ $currency }} {{ number_format((float) $return->total_amount, 2) }}</p>
    @endforeach
    <div class="footer">
        <p>{{ Setting::get('invoice_footer_note', __('Thank you for your business.')) }}</p>
        <p class="muted">{{ Setting::get('invoice_terms', '') }}</p>
        @if (config('app.dev_name'))<p class="muted">{{ __('Powered by :name', ['name' => config('app.dev_name')]) }}</p>@endif
    </div>
</body>
</html>
