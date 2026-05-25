<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $meta['title'] }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .header {
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        .header table {
            width: 100%;
            border: none;
        }
        .header td {
            vertical-align: top;
            border: none;
        }
        .business-info h1 {
            margin: 0;
            font-size: 24px;
            color: #111;
        }
        .business-info p {
            margin: 2px 0;
            color: #555;
        }
        .report-info h2 {
            margin: 0 0 5px 0;
            font-size: 20px;
            color: #5b21b6; /* violet-800 */
        }
        .report-info p {
            margin: 2px 0;
        }
        .summary-boxes {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: separate;
            border-spacing: 10px 0;
        }
        .summary-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px;
            text-align: center;
            width: 20%;
        }
        .summary-title {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 5px;
        }
        .summary-value {
            font-size: 16px;
            font-weight: bold;
        }
        .text-emerald { color: #059669; }
        .text-rose { color: #e11d48; }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .data-table th, .data-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 8px 10px;
            text-align: left;
        }
        .data-table th {
            background-color: #f1f5f9;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #475569;
        }
        .data-table .text-right { text-align: right; }
        .data-table .text-center { text-align: center; }
        .data-table tr:nth-child(even) { background-color: #f8fafc; }
        
        .footer {
            width: 100%;
            margin-top: 50px;
        }
        .signatures {
            width: 100%;
            margin-top: 50px;
        }
        .signatures table {
            width: 100%;
            border: none;
        }
        .signatures td {
            text-align: center;
            border: none;
        }
        .signature-line {
            display: inline-block;
            width: 200px;
            border-top: 1px solid #000;
            margin-top: 50px;
            padding-top: 5px;
        }
    </style>
</head>
<body>

    <div class="header">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td class="business-info" width="60%">
                    <h1>{{ $businessName ?: 'Accounting System' }}</h1>
                    @if($businessAddress)<p>{{ $businessAddress }}</p>@endif
                    @if($businessPhone)<p>Phone: {{ $businessPhone }}</p>@endif
                </td>
                <td class="report-info" width="40%" align="right">
                    <h2>{{ $meta['title'] }}</h2>
                    <p><strong>Period:</strong> {{ $startDate }} to {{ $endDate }}</p>
                    <p><strong>Method:</strong> {{ $paymentMethod }}</p>
                    @if($search)
                    <p><strong>Search:</strong> {{ $search }}</p>
                    @endif
                    <p><strong>Generated:</strong> {{ now()->format('Y-m-d H:i') }}</p>
                </td>
            </tr>
        </table>
    </div>

    <!-- Summary Totals -->
    <table class="summary-boxes" cellspacing="0" cellpadding="0">
        <tr>
            <td class="summary-box">
                <div class="summary-title">Receivables</div>
                <div class="summary-value text-rose">Rs {{ number_format($totalReceivables, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="summary-title">Payables</div>
                <div class="summary-value text-rose">Rs {{ number_format($totalPayables, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="summary-title">Cash In</div>
                <div class="summary-value text-emerald">Rs {{ number_format($totalCashInflow, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="summary-title">Cash Out</div>
                <div class="summary-value text-rose">Rs {{ number_format($totalCashOutflow, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="summary-title">Net Flow</div>
                <div class="summary-value {{ $netCashFlow >= 0 ? 'text-emerald' : 'text-rose' }}">Rs {{ number_format($netCashFlow, 2) }}</div>
            </td>
        </tr>
    </table>

    <!-- Main Data Table -->
    @if ($reportType === 'daily-cash-closing')
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Cash In</th>
                    <th class="text-right">Cash Out</th>
                    <th class="text-right">Net</th>
                    <th class="text-right">Closing Balance</th>
                    <th class="text-right">Entries</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dailyClosingRows as $row)
                    <tr>
                        <td><strong>{{ $row['date'] }}</strong></td>
                        <td class="text-right text-emerald">Rs {{ number_format($row['debit'], 2) }}</td>
                        <td class="text-right text-rose">Rs {{ number_format($row['credit'], 2) }}</td>
                        <td class="text-right {{ $row['net'] >= 0 ? 'text-emerald' : 'text-rose' }}">Rs {{ number_format($row['net'], 2) }}</td>
                        <td class="text-right"><strong>Rs {{ number_format($row['closing_balance'], 2) }}</strong></td>
                        <td class="text-right">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center">{{ $meta['empty'] }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($reportType === 'daily-register-closing')
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Sales Receipts</th>
                    <th class="text-right">Due Collections</th>
                    <th class="text-right">Purchase Payments</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($registerClosingRows as $row)
                    <tr>
                        <td><strong>{{ $row['date'] }}</strong></td>
                        <td class="text-right text-emerald">Rs {{ number_format($row['sales_receipts'], 2) }}</td>
                        <td class="text-right text-emerald">Rs {{ number_format($row['due_collections'], 2) }}</td>
                        <td class="text-right text-rose">Rs {{ number_format($row['purchase_payments'], 2) }}</td>
                        <td class="text-right text-rose">Rs {{ number_format($row['expenses'], 2) }}</td>
                        <td class="text-right {{ $row['net'] >= 0 ? 'text-emerald' : 'text-rose' }}"><strong>Rs {{ number_format($row['net'], 2) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center">{{ $meta['empty'] }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($reportType === 'payment-method-report')
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                    <th class="text-right">Net</th>
                    <th class="text-right">Entries</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($paymentMethodRows as $row)
                    <tr>
                        <td>{{ str($row['method'])->replace('_', ' ')->headline() }}</td>
                        <td class="text-right text-emerald">Rs {{ number_format($row['debit'], 2) }}</td>
                        <td class="text-right text-rose">Rs {{ number_format($row['credit'], 2) }}</td>
                        <td class="text-right {{ $row['net'] >= 0 ? 'text-emerald' : 'text-rose' }}"><strong>Rs {{ number_format($row['net'], 2) }}</strong></td>
                        <td class="text-right">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center">{{ $meta['empty'] }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($reportType === 't-accounts')
        @if($tAccountRows->isEmpty())
            <table class="data-table">
                <tbody>
                    <tr><td class="text-center">{{ $meta['empty'] }}</td></tr>
                </tbody>
            </table>
        @else
            <table width="100%" cellspacing="0" cellpadding="10" style="border: none; margin-bottom: 30px;">
                @php $chunked = collect($tAccountRows)->chunk(2); @endphp
                @foreach ($chunked as $chunk)
                    <tr>
                        @foreach ($chunk as $row)
                            <td width="50%" valign="top" style="border: none; padding-bottom: 20px;">
                                <table style="width: 100%; border-collapse: collapse; border: 1px solid #94a3b8;">
                                    <tr>
                                        <th colspan="2" style="border-bottom: 2px solid #1e293b; text-align: center; padding: 8px; background-color: #f1f5f9; font-size: 14px; font-weight: bold; color: #0f172a;">
                                            {{ $row['account'] }}
                                        </th>
                                    </tr>
                                    <tr>
                                        <th style="width: 50%; border-right: 1px solid #94a3b8; border-bottom: 1px solid #cbd5e1; text-align: left; padding: 5px; font-size: 10px; text-transform: uppercase;">Debit</th>
                                        <th style="width: 50%; border-bottom: 1px solid #cbd5e1; text-align: left; padding: 5px; font-size: 10px; text-transform: uppercase;">Credit</th>
                                    </tr>
                                    <tr>
                                        <td style="width: 50%; border-right: 1px solid #94a3b8; vertical-align: top; padding: 0;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                @foreach($row['debits'] as $txn)
                                                    <tr>
                                                        <td style="padding: 3px 5px; font-size: 10px; border: none; color: #64748b;">{{ $txn['date']->format('m/d') }}</td>
                                                        <td style="padding: 3px 5px; font-size: 10px; text-align: right; border: none; font-weight: bold; color: #059669;">{{ number_format($txn['debit'], 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        </td>
                                        <td style="width: 50%; vertical-align: top; padding: 0;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                @foreach($row['credits'] as $txn)
                                                    <tr>
                                                        <td style="padding: 3px 5px; font-size: 10px; border: none; color: #64748b;">{{ $txn['date']->format('m/d') }}</td>
                                                        <td style="padding: 3px 5px; font-size: 10px; text-align: right; border: none; font-weight: bold; color: #e11d48;">{{ number_format($txn['credit'], 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="border-right: 1px solid #94a3b8; border-top: 1px solid #94a3b8; padding: 5px;">
                                            <table style="width: 100%; border: none;">
                                                <tr>
                                                    <td style="border: none; padding: 0; font-size: 10px; color: #64748b;">Total</td>
                                                    <td style="border: none; padding: 0; text-align: right; font-weight: bold;">{{ number_format($row['total_debit'], 2) }}</td>
                                                </tr>
                                                @if($row['balance'] >= 0)
                                                <tr>
                                                    <td style="border: none; padding: 0; font-size: 10px; font-weight: bold; color: #059669; text-transform: uppercase;">Bal</td>
                                                    <td style="border: none; padding: 0; text-align: right; font-weight: bold; color: #059669;">{{ number_format($row['balance'], 2) }}</td>
                                                </tr>
                                                @endif
                                            </table>
                                        </td>
                                        <td style="border-top: 1px solid #94a3b8; padding: 5px;">
                                            <table style="width: 100%; border: none;">
                                                <tr>
                                                    <td style="border: none; padding: 0; font-size: 10px; color: #64748b;">Total</td>
                                                    <td style="border: none; padding: 0; text-align: right; font-weight: bold;">{{ number_format($row['total_credit'], 2) }}</td>
                                                </tr>
                                                @if($row['balance'] < 0)
                                                <tr>
                                                    <td style="border: none; padding: 0; font-size: 10px; font-weight: bold; color: #e11d48; text-transform: uppercase;">Bal</td>
                                                    <td style="border: none; padding: 0; text-align: right; font-weight: bold; color: #e11d48;">{{ number_format(abs($row['balance']), 2) }}</td>
                                                </tr>
                                                @endif
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        @endforeach
                        @if ($chunk->count() === 1)
                            <td width="50%" style="border: none;"></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transaction / Reference</th>
                    <th>Account</th>
                    <th>Method</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                    @if ($reportType === 'cash-balance')
                        <th class="text-right">Balance</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @php $rows = $reportType === 'cash-balance' ? $balanceRows : $reportTransactions; @endphp
                @forelse ($rows as $transaction)
                    <tr>
                        <td>{{ $transaction['date']->format('Y-m-d') }}</td>
                        <td>
                            <strong>{{ $transaction['description'] }}</strong>
                            @if ($transaction['reference'])
                                <br><small style="color: #64748b;">{{ $transaction['reference'] }}</small>
                            @endif
                        </td>
                        <td>{{ $transaction['account'] }}</td>
                        <td>{{ str($transaction['method'])->replace('_', ' ')->headline() }}</td>
                        <td class="text-right text-emerald">
                            {{ $transaction['debit'] > 0 ? 'Rs '.number_format($transaction['debit'], 2) : '-' }}
                        </td>
                        <td class="text-right text-rose">
                            {{ $transaction['credit'] > 0 ? 'Rs '.number_format($transaction['credit'], 2) : '-' }}
                        </td>
                        @if ($reportType === 'cash-balance')
                            <td class="text-right {{ $transaction['balance'] >= 0 ? 'text-emerald' : 'text-rose' }}">
                                <strong>Rs {{ number_format($transaction['balance'], 2) }}</strong>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $reportType === 'cash-balance' ? 7 : 6 }}" class="text-center">{{ $meta['empty'] }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="signatures">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    <span class="signature-line">Prepared By</span>
                </td>
                <td>
                    <span class="signature-line">Reviewed By</span>
                </td>
                <td>
                    <span class="signature-line">Authorized Signatory</span>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
