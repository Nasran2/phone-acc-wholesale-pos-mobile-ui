<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AccountingLedgerService
{
    /**
     * @return array{0: string, 1: string}
     */
    public function periodFor(string $dateRange, ?string $customStartDate = null, ?string $customEndDate = null): array
    {
        return match ($dateRange) {
            'yesterday' => [
                today()->subDay()->toDateString(),
                today()->subDay()->toDateString(),
            ],
            '7days' => [
                today()->subDays(7)->toDateString(),
                today()->toDateString(),
            ],
            '30days' => [
                today()->subDays(30)->toDateString(),
                today()->toDateString(),
            ],
            'custom' => [
                $customStartDate ?: today()->toDateString(),
                $customEndDate ?: today()->toDateString(),
            ],
            default => [
                today()->toDateString(),
                today()->toDateString(),
            ],
        };
    }

    /**
     * @return Collection<int, array{
     *     date: Carbon,
     *     description: string,
     *     method: string,
     *     reference: string|null,
     *     account: string,
     *     source: string,
     *     source_type: string,
     *     debit: float,
     *     credit: float,
     *     raw_date: Carbon|null
     * }>
     */
    public function transactions(string $startDate, string $endDate, ?string $paymentMethod = null, ?string $search = null): Collection
    {
        $inflows = Payment::query()
            ->with('paymentable')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereHasMorph('paymentable', [Customer::class, Sale::class])
            ->where(function ($query): void {
                $query->where('payment_method', '!=', 'cheque')
                    ->orWhere('cheque_status', 'passed');
            })
            ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('notes', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhere('payment_method', 'like', "%{$term}%");
                });
            })
            ->get()
            ->map(fn (Payment $payment): array => $this->mapPayment($payment, 'debit'));

        $purchaseOutflows = Payment::query()
            ->with('paymentable')
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->whereHasMorph('paymentable', [Supplier::class, Purchase::class])
            ->where(function ($query): void {
                $query->where('payment_method', '!=', 'cheque')
                    ->orWhere('cheque_status', 'passed');
            })
            ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('notes', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhere('payment_method', 'like', "%{$term}%");
                });
            })
            ->get()
            ->map(fn (Payment $payment): array => $this->mapPayment($payment, 'credit'));

        $expenseOutflows = Expense::query()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($search, function ($query, string $term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('category', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhere('payment_method', 'like', "%{$term}%");
                });
            })
            ->get()
            ->map(fn (Expense $expense): array => $this->mapExpense($expense));

        return $inflows
            ->concat($purchaseOutflows)
            ->concat($expenseOutflows)
            ->sortBy([
                ['date', 'asc'],
                ['raw_date', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{date: Carbon, debit: float, credit: float}>  $transactions
     * @return Collection<int, array{date: string, debit: float, credit: float, net: float, closing_balance: float, count: int}>
     */
    public function dailyClosingRows(Collection $transactions): Collection
    {
        $runningBalance = 0.00;

        return $transactions
            ->groupBy(fn (array $transaction): string => $transaction['date']->format('Y-m-d'))
            ->map(function (Collection $dayTransactions, string $date) use (&$runningBalance): array {
                $debit = (float) $dayTransactions->sum('debit');
                $credit = (float) $dayTransactions->sum('credit');
                $runningBalance += $debit - $credit;

                return [
                    'date' => $date,
                    'debit' => $debit,
                    'credit' => $credit,
                    'net' => $debit - $credit,
                    'closing_balance' => $runningBalance,
                    'count' => $dayTransactions->count(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{date: Carbon, account: string, source: string, debit: float, credit: float}>  $transactions
     * @return Collection<int, array{date: string, sales_receipts: float, due_collections: float, purchase_payments: float, expenses: float, net: float, count: int}>
     */
    public function registerClosingRows(Collection $transactions): Collection
    {
        return $transactions
            ->groupBy(fn (array $transaction): string => $transaction['date']->format('Y-m-d'))
            ->map(function (Collection $dayTransactions, string $date): array {
                $salesReceipts = (float) $dayTransactions->where('account', 'Sales Revenue')->sum('debit');
                $dueCollections = (float) $dayTransactions->where('account', 'Accounts Receivable')->sum('debit');
                $purchasePayments = (float) $dayTransactions->where('account', 'Purchase Payments')->sum('credit')
                    + (float) $dayTransactions->where('account', 'Accounts Payable')->sum('credit');
                $expenses = (float) $dayTransactions->where('account', 'Operating Expenses')->sum('credit');

                return [
                    'date' => $date,
                    'sales_receipts' => $salesReceipts,
                    'due_collections' => $dueCollections,
                    'purchase_payments' => $purchasePayments,
                    'expenses' => $expenses,
                    'net' => $salesReceipts + $dueCollections - $purchasePayments - $expenses,
                    'count' => $dayTransactions->count(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{method: string, debit: float, credit: float}>  $transactions
     * @return Collection<int, array{method: string, debit: float, credit: float, net: float, count: int}>
     */
    public function paymentMethodRows(Collection $transactions): Collection
    {
        return $transactions
            ->groupBy('method')
            ->map(fn (Collection $methodTransactions, string $method): array => [
                'method' => $method,
                'debit' => (float) $methodTransactions->sum('debit'),
                'credit' => (float) $methodTransactions->sum('credit'),
                'net' => (float) $methodTransactions->sum('debit') - (float) $methodTransactions->sum('credit'),
                'count' => $methodTransactions->count(),
            ])
            ->sortBy('method')
            ->values();
    }

    /**
     * @param  Collection<int, array{date: Carbon, description: string, method: string, reference: string|null, account: string, source: string, source_type: string, debit: float, credit: float, raw_date: Carbon|null}>  $transactions
     * @return Collection<int, array{account: string, debits: Collection, credits: Collection, total_debit: float, total_credit: float, balance: float, count: int}>
     */
    public function tAccountRows(Collection $transactions): Collection
    {
        return $transactions
            ->groupBy('account')
            ->map(function (Collection $accountTransactions, string $account): array {
                $totalDebit = (float) $accountTransactions->sum('debit');
                $totalCredit = (float) $accountTransactions->sum('credit');

                return [
                    'account' => $account,
                    'debits' => $accountTransactions->filter(fn (array $t): bool => $t['debit'] > 0)->values(),
                    'credits' => $accountTransactions->filter(fn (array $t): bool => $t['credit'] > 0)->values(),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'balance' => $totalDebit - $totalCredit,
                    'count' => $accountTransactions->count(),
                ];
            })
            ->sortBy('account')
            ->values();
    }

    /**
     * @param  Collection<int, array{date: Carbon, description: string, method: string, reference: string|null, account: string, source: string, source_type: string, debit: float, credit: float, raw_date: Carbon|null}>  $transactions
     * @return Collection<int, array{date: Carbon, description: string, method: string, reference: string|null, account: string, source: string, source_type: string, debit: float, credit: float, raw_date: Carbon|null, balance: float}>
     */
    public function balanceRows(Collection $transactions): Collection
    {
        $balance = 0.00;

        return $transactions->map(function (array $transaction) use (&$balance): array {
            $balance += $transaction['debit'] - $transaction['credit'];

            return [
                ...$transaction,
                'balance' => $balance,
            ];
        });
    }

    /**
     * @return array{date: Carbon, description: string, method: string, reference: string|null, account: string, source: string, source_type: string, debit: float, credit: float, raw_date: Carbon|null}
     */
    private function mapPayment(Payment $payment, string $side): array
    {
        $isDebit = $side === 'debit';
        $paymentable = $payment->paymentable;
        $account = match (true) {
            $paymentable instanceof Customer => 'Accounts Receivable',
            $paymentable instanceof Sale => 'Sales Revenue',
            $paymentable instanceof Supplier => 'Accounts Payable',
            $paymentable instanceof Purchase => 'Purchase Payments',
            default => 'Payments',
        };

        return [
            'date' => $payment->date,
            'description' => $payment->notes ?: $this->defaultPaymentDescription($payment),
            'method' => $payment->payment_method,
            'reference' => $payment->reference,
            'account' => $account,
            'source' => class_basename((string) $payment->paymentable_type),
            'source_type' => $isDebit ? 'cash_in' : 'cash_out',
            'debit' => $isDebit ? (float) $payment->amount : 0.00,
            'credit' => $isDebit ? 0.00 : (float) $payment->amount,
            'raw_date' => $payment->created_at,
        ];
    }

    /**
     * @return array{date: Carbon, description: string, method: string, reference: string|null, account: string, source: string, source_type: string, debit: float, credit: float, raw_date: Carbon|null}
     */
    private function mapExpense(Expense $expense): array
    {
        return [
            'date' => $expense->date,
            'description' => '[EXPENSE] '.$expense->category.($expense->notes ? ' - '.$expense->notes : ''),
            'method' => $expense->payment_method,
            'reference' => $expense->reference,
            'account' => 'Operating Expenses',
            'source' => 'Expense',
            'source_type' => 'cash_out',
            'debit' => 0.00,
            'credit' => (float) $expense->amount,
            'raw_date' => $expense->created_at,
        ];
    }

    private function defaultPaymentDescription(Payment $payment): string
    {
        return match ($payment->paymentable_type) {
            Customer::class => 'Due payment received',
            Sale::class => 'Customer receipt checkout transaction',
            Supplier::class => 'Supplier dues remitted',
            Purchase::class => 'Wholesale restock payment',
            default => 'Payment transaction',
        };
    }
}
