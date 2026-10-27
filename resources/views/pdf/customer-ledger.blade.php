<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $customer->name }} - Ledger</title>
    <style>
        @page { margin: 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172554; }
        h1 { color: #0369a1; font-size: 22px; }
        p { margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 20px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 8px 4px; border-bottom: 1px solid #dbeafe; word-wrap: break-word; }
        th { background: #eff6ff; text-align: left; }
        .number { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ App\Models\Setting::get('business_name', config('app.name')) }}</h1>
    <h2>{{ __('Account Ledger') }} &middot; {{ $customer->name }}</h2>
    <p>{{ $customer->phone }} {{ $customer->address }}</p>
    <p>{{ __('Generated') }}: {{ now()->format('Y-m-d H:i') }}</p>
    <p>{{ __('Opening Balance') }}: {{ number_format((float) $customer->opening_balance, 2) }} &middot; {{ __('Outstanding Balance') }}: {{ number_format((float) $customer->due_balance, 2) }}</p>
    <table>
        <thead><tr><th style="width: 14%">Bill Date</th><th style="width: 14%">Paid Date</th><th style="width: 18%">Bill No</th><th style="width: 30%">Description</th><th class="number" style="width: 12%">Debit (+)</th><th class="number" style="width: 12%">Credit (-)</th></tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr><td>{{ $row['bill_date']?->format('Y-m-d') ?? '-' }}</td><td>{{ $row['paid_date']?->format('Y-m-d') ?? '-' }}</td><td>{{ $row['bill_no'] }}</td><td>{{ $row['description'] }} @if ($row['payment_method'])({{ $row['payment_method'] }})@endif</td><td class="number">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '-' }}</td><td class="number">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '-' }}</td></tr>
            @empty
                <tr><td colspan="6">{{ __('No transactions found for this account.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
