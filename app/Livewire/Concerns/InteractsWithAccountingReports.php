<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\AccountingLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait InteractsWithAccountingReports
{
    #[Url(as: 'range')]
    public string $dateRange = 'today';

    #[Url(as: 'from')]
    public ?string $customStartDate = null;

    #[Url(as: 'to')]
    public ?string $customEndDate = null;

    #[Url(as: 'method')]
    public string $paymentMethod = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->customStartDate ??= today()->toDateString();
        $this->customEndDate ??= today()->toDateString();
    }

    public function clearFilters(): void
    {
        $this->dateRange = 'today';
        $this->customStartDate = today()->toDateString();
        $this->customEndDate = today()->toDateString();
        $this->paymentMethod = 'all';
        $this->search = '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    #[Computed]
    public function filteredPeriod(): array
    {
        return $this->ledger()->periodFor(
            $this->dateRange,
            $this->customStartDate,
            $this->customEndDate,
        );
    }

    #[Computed]
    public function totalReceivables(): float
    {
        return (float) Customer::query()->sum('due_balance');
    }

    #[Computed]
    public function totalPayables(): float
    {
        return (float) Supplier::query()->sum('due_balance');
    }

    #[Computed]
    public function transactions(): Collection
    {
        [$start, $end] = $this->filteredPeriod;

        return $this->ledger()->transactions(
            $start,
            $end,
            $this->paymentMethod === 'all' ? null : $this->paymentMethod,
            filled($this->search) ? trim($this->search) : null,
        );
    }

    #[Computed]
    public function availablePaymentMethods(): array
    {
        [$start, $end] = $this->filteredPeriod;

        return $this->ledger()
            ->transactions($start, $end)
            ->pluck('method')
            ->merge(['cash', 'card', 'qr', 'bank_transfer', 'cheque'])
            ->unique()
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    #[Computed]
    public function reportTransactions(): Collection
    {
        return match ($this->reportType) {
            'cash-in' => $this->transactions->where('source_type', 'cash_in')->values(),
            'cash-out' => $this->transactions->where('source_type', 'cash_out')->values(),
            'bank-transfers' => $this->transactions->where('method', 'bank_transfer')->values(),
            default => $this->transactions,
        };
    }

    #[Computed]
    public function totalCashInflow(): float
    {
        return (float) $this->reportTransactions->sum('debit');
    }

    #[Computed]
    public function totalCashOutflow(): float
    {
        return (float) $this->reportTransactions->sum('credit');
    }

    #[Computed]
    public function netCashFlow(): float
    {
        return $this->totalCashInflow - $this->totalCashOutflow;
    }

    #[Computed]
    public function dailyClosingRows(): Collection
    {
        return $this->ledger()->dailyClosingRows($this->reportTransactions);
    }

    #[Computed]
    public function registerClosingRows(): Collection
    {
        return $this->ledger()->registerClosingRows($this->reportTransactions);
    }

    #[Computed]
    public function paymentMethodRows(): Collection
    {
        return $this->ledger()->paymentMethodRows($this->reportTransactions);
    }

    #[Computed]
    public function tAccountRows(): Collection
    {
        return $this->ledger()->tAccountRows($this->reportTransactions);
    }

    #[Computed]
    public function balanceRows(): Collection
    {
        return $this->ledger()->balanceRows($this->reportTransactions);
    }

    /**
     * @return array<int, array{route: string, label: string}>
     */
    #[Computed]
    public function reportPages(): array
    {
        return [
            ['route' => 'accounting.cash-book', 'label' => 'Cash Book'],
            ['route' => 'accounting.daily-cash-closing', 'label' => 'Daily Cash Closing'],
            ['route' => 'accounting.daily-register-closing', 'label' => 'Daily Register Closing'],
            ['route' => 'accounting.cash-in', 'label' => 'Cash In'],
            ['route' => 'accounting.cash-out', 'label' => 'Cash Out'],
            ['route' => 'accounting.cash-balance', 'label' => 'Cash Balance'],
            ['route' => 'accounting.bank-transfers', 'label' => 'Bank Transfers'],
            ['route' => 'accounting.payment-method-report', 'label' => 'Payment Method Report'],
            ['route' => 'accounting.t-accounts', 'label' => 'T Accounts'],
        ];
    }

    /**
     * @return array{eyebrow: string, title: string, description: string, empty: string}
     */
    #[Computed]
    public function reportMeta(): array
    {
        return match ($this->reportType) {
            'daily-cash-closing' => [
                'eyebrow' => 'Daily Closing',
                'title' => 'Daily Cash Closing',
                'description' => 'Day-by-day debit, credit, net movement, and closing balance for the selected period.',
                'empty' => 'No daily cash closing rows for this selected period.',
            ],
            'daily-register-closing' => [
                'eyebrow' => 'Register Closing',
                'title' => 'Daily Register Closing',
                'description' => 'Daily register totals separated by sales receipts, due collections, purchase payments, and expenses.',
                'empty' => 'No daily register closing rows for this selected period.',
            ],
            'cash-in' => [
                'eyebrow' => 'Receipts',
                'title' => 'Cash In',
                'description' => 'All verified customer receipts, sale payments, card, QR, bank, and passed cheque inflows.',
                'empty' => 'No cash-in transactions for this selected period.',
            ],
            'cash-out' => [
                'eyebrow' => 'Payments',
                'title' => 'Cash Out',
                'description' => 'Supplier remittances, purchase payments, and overhead expenses paid during the period.',
                'empty' => 'No cash-out transactions for this selected period.',
            ],
            'cash-balance' => [
                'eyebrow' => 'Running Balance',
                'title' => 'Cash Balance',
                'description' => 'Chronological cash movement with a running period balance after each transaction.',
                'empty' => 'No balance movement for this selected period.',
            ],
            'bank-transfers' => [
                'eyebrow' => 'Bank Ledger',
                'title' => 'Bank Transfers',
                'description' => 'Direct bank transfer inflows and outflows with references and account impact.',
                'empty' => 'No bank transfer transactions for this selected period.',
            ],
            'payment-method-report' => [
                'eyebrow' => 'Payment Methods',
                'title' => 'Payment Method Report',
                'description' => 'Totals grouped by cash, card, QR, direct bank transfer, and cleared cheque methods.',
                'empty' => 'No payment method totals for this selected period.',
            ],
            't-accounts' => [
                'eyebrow' => 'Double Entry',
                'title' => 'T Accounts',
                'description' => 'Debit and credit totals grouped by ledger account for quick accounting review.',
                'empty' => 'No T account totals for this selected period.',
            ],
            default => [
                'eyebrow' => 'Cash Book',
                'title' => 'Shop Cash Book',
                'description' => 'Detailed chronological double-entry log of operational transactions.',
                'empty' => 'No accounting transactions logged for this selected period.',
            ],
        };
    }

    public function methodLabel(string $method): string
    {
        return str($method)->replace('_', ' ')->headline()->toString();
    }

    public function downloadPdf()
    {
        $data = [
            'reportType' => $this->reportType,
            'meta' => $this->reportMeta,
            'startDate' => $this->filteredPeriod[0],
            'endDate' => $this->filteredPeriod[1],
            'totalReceivables' => $this->totalReceivables,
            'totalPayables' => $this->totalPayables,
            'totalCashInflow' => $this->totalCashInflow,
            'totalCashOutflow' => $this->totalCashOutflow,
            'netCashFlow' => $this->netCashFlow,
            'paymentMethod' => $this->paymentMethod === 'all' ? 'All Methods' : $this->methodLabel($this->paymentMethod),
            'search' => $this->search,
            'reportTransactions' => $this->reportTransactions,
            'dailyClosingRows' => $this->reportType === 'daily-cash-closing' ? $this->dailyClosingRows : collect(),
            'registerClosingRows' => $this->reportType === 'daily-register-closing' ? $this->registerClosingRows : collect(),
            'paymentMethodRows' => $this->reportType === 'payment-method-report' ? $this->paymentMethodRows : collect(),
            'tAccountRows' => $this->reportType === 't-accounts' ? $this->tAccountRows : collect(),
            'balanceRows' => $this->reportType === 'cash-balance' ? $this->balanceRows : collect(),
            'businessName' => Setting::get('business_name', config('app.name')),
            'businessAddress' => Setting::get('business_address', ''),
            'businessPhone' => Setting::get('business_phone', ''),
        ];

        $pdf = Pdf::loadView('pdf.accounting-report', $data)
            ->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "{$this->reportType}-report.pdf"
        );
    }

    private function ledger(): AccountingLedgerService
    {
        return app(AccountingLedgerService::class);
    }
}
