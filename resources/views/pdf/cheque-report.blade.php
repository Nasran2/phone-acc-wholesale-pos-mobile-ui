<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cheque Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            padding: 0;
            font-size: 18px;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 12px;
            color: #666;
        }
        .filters {
            margin-bottom: 15px;
            font-size: 11px;
            color: #555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f9fafb;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: bold;
            color: #555;
        }
        .amount {
            text-align: right;
            font-weight: bold;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-passed {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge-returned {
            background-color: #ffe4e6;
            color: #9f1239;
        }
        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>

    <div class="header">
        <h2>Cheque Report</h2>
        <p>Generated on {{ now()->format('Y-m-d H:i') }}</p>
    </div>

    <div class="filters">
        <strong>Filters Applied:</strong><br>
        Status: {{ ucfirst($status) }} <br>
        @if($isNonSupplier) Supplier: Non Supplier (-) <br> @elseif($supplier) Supplier: {{ $supplier->name }} <br> @endif
        @if($startDate) From: {{ $startDate }} <br> @endif
        @if($endDate) To: {{ $endDate }} <br> @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Cheque Details</th>
                <th class="amount">Amount</th>
                <th>Customer</th>
                <th>Supplier</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($cheques as $pm)
                <tr>
                    <td>
                        <strong>{{ $pm->cheque_no ?: ($pm->reference ?: 'N/A') }}</strong><br>
                        <span style="color: #666; font-size: 10px;">{{ $pm->cheque_bank ?: 'Bank N/A' }}</span><br>
                        <span style="color: #888; font-size: 10px;">{{ $pm->cheque_date ? $pm->cheque_date->format('Y-m-d') : 'No Date' }}</span>
                    </td>
                    <td class="amount">Rs {{ number_format($pm->amount, 2) }}</td>
                    <td>{{ method_exists($pm, 'resolveCustomerName') && $pm->resolveCustomerName() !== __('Unknown Customer') ? $pm->resolveCustomerName() : '-' }}</td>
                    <td>
                        @if ($pm->paymentable instanceof \App\Models\Purchase)
                            {{ $pm->paymentable->supplier?->name ?? '-' }}
                        @elseif ($pm->paymentable instanceof \App\Models\Supplier)
                            {{ $pm->paymentable->name }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($pm->cheque_status === 'passed')
                            <span class="badge badge-passed">Passed</span>
                        @elseif ($pm->cheque_status === 'returned')
                            <span class="badge badge-returned">Returned</span>
                        @else
                            <span class="badge badge-pending">Pending</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #888;">No cheques found matching the criteria.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
