<?php

namespace App\Livewire\Concerns;

use App\Services\BusinessReportService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait InteractsWithBusinessReports
{
    #[Url(as: 'range')]
    public string $dateRange = '7days';

    #[Url(as: 'from')]
    public ?string $customStartDate = null;

    #[Url(as: 'to')]
    public ?string $customEndDate = null;

    #[Url(as: 'status')]
    public string $reportStatus = 'all';

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
        $this->dateRange = '7days';
        $this->customStartDate = today()->toDateString();
        $this->customEndDate = today()->toDateString();
        $this->reportStatus = 'all';
        $this->paymentMethod = 'all';
        $this->search = '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    #[Computed]
    public function filteredPeriod(): array
    {
        return $this->reports()->periodFor($this->dateRange, $this->customStartDate, $this->customEndDate);
    }

    #[Computed]
    public function meta(): array
    {
        return $this->reports()->meta($this->reportType);
    }

    #[Computed]
    public function business(): array
    {
        return $this->reports()->businessDetails();
    }

    #[Computed]
    public function reportPages(): array
    {
        return $this->reports()->pages();
    }

    #[Computed]
    public function rows(): Collection
    {
        [$start, $end] = $this->filteredPeriod;

        return $this->reports()->rows(
            $this->reportType,
            $start,
            $end,
            $this->reportStatus,
            $this->paymentMethod,
            filled($this->search) ? trim($this->search) : null,
        );
    }

    #[Computed]
    public function summary(): array
    {
        return $this->reports()->summary($this->reportType, $this->rows);
    }

    #[Computed]
    public function columns(): array
    {
        return $this->reports()->columns($this->reportType);
    }

    #[Computed]
    public function generatedAt(): string
    {
        return now()->format('Y-m-d H:i');
    }

    public function money(float|int|string|null $amount): string
    {
        return $this->business['currency'].' '.number_format((float) $amount, 2);
    }

    public function displayValue(array $row, array $column): string
    {
        $value = $row[$column['key']] ?? '-';

        if (($column['money'] ?? false) === true) {
            return $this->money($value);
        }

        return (string) ($value === '' || $value === null ? '-' : $value);
    }

    public function toneClass(string $tone, string $surface = 'text'): string
    {
        if ($surface === 'box') {
            return match ($tone) {
                'emerald' => 'border-emerald-100 bg-emerald-50/60 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300',
                'rose' => 'border-rose-100 bg-rose-50/60 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300',
                'violet' => 'border-violet-100 bg-violet-50/60 text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-300',
                default => 'border-zinc-100 bg-zinc-50/70 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-300',
            };
        }

        return match ($tone) {
            'emerald' => 'text-emerald-600',
            'rose' => 'text-rose-600',
            'violet' => 'text-violet-600',
            default => 'text-zinc-700 dark:text-zinc-300',
        };
    }

    public function statusOptions(): array
    {
        return match ($this->reportType) {
            'stock' => [
                'all' => 'All Stock',
                'in_stock' => 'In Stock',
                'low_stock' => 'Low Stock',
                'out_of_stock' => 'Out of Stock',
            ],
            'due-bills' => [
                'all' => 'All Due Bills',
                'customer_due' => 'Customer Due',
                'supplier_due' => 'Supplier Due',
            ],
            'customer-dues' => [
                'all' => 'All Customers',
                'with_due' => 'With Due',
                'no_due' => 'Settled',
            ],
            default => [
                'all' => 'All Status',
                'paid' => 'Paid',
                'partial' => 'Partial',
                'due' => 'Due',
                'cheque_pending' => 'Cheque Pending',
            ],
        };
    }

    public function usesPaymentMethodFilter(): bool
    {
        return in_array($this->reportType, ['expenses', 'receives', 'debits'], true);
    }

    private function reports(): BusinessReportService
    {
        return app(BusinessReportService::class);
    }
}
