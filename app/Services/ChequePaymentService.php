<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChequePaymentService
{
    public function __construct(private SmsNotificationService $smsNotificationService) {}

    public function pass(Payment $payment): Payment
    {
        $shouldNotify = false;

        $passedPayment = DB::transaction(function () use ($payment, &$shouldNotify) {
            $payment->refresh();

            if ($payment->payment_method !== 'cheque' || $payment->cheque_status !== 'pending') {
                return $payment;
            }

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->applyPassedChequeToSale($sale, (float) $payment->amount);
            }

            $payment->update([
                'cheque_status' => 'passed',
                'cheque_processed_at' => now(),
            ]);

            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            $shouldNotify = true;

            return $payment->refresh();
        });

        if ($shouldNotify) {
            $this->smsNotificationService->notifyChequePassed($passedPayment);
        }

        return $passedPayment;
    }

    public function markReturned(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment->refresh();

            if ($payment->payment_method !== 'cheque' || $payment->cheque_status !== 'pending') {
                return $payment;
            }

            $payment->update([
                'cheque_status' => 'returned',
                'cheque_processed_at' => now(),
            ]);

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            return $payment->refresh();
        });
    }

    public function autoPassOverduePendingCheques(?CarbonInterface $today = null): int
    {
        $today ??= today();
        $cutoff = $today->copy()->subDays(7)->toDateString();
        $processed = 0;

        Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDate('cheque_date', '<=', $cutoff)
            ->with('paymentable.customer')
            ->orderBy('cheque_date')
            ->each(function (Payment $payment) use (&$processed) {
                $this->pass($payment);
                $processed++;
            });

        return $processed;
    }

    public function actionablePendingCheques(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        $this->autoPassOverduePendingCheques($today);

        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDate('cheque_date', '<=', $today->copy()->addDays(2)->toDateString())
            ->with('paymentable.customer')
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->get();
    }

    private function applyPassedChequeToSale(Sale $sale, float $amount): void
    {
        $sale->refresh();

        $remainingBalance = max(0, (float) $sale->grand_total - (float) $sale->paid_amount);
        $acceptedAmount = min($amount, $remainingBalance);

        if ($acceptedAmount <= 0) {
            return;
        }

        $sale->update([
            'paid_amount' => round((float) $sale->paid_amount + $acceptedAmount, 2),
        ]);
    }

    private function syncSaleStatus(Sale $sale): void
    {
        $sale->refresh();
        $oldDueAmount = (float) $sale->due_amount;
        $pendingChequeAmount = (float) $sale->payments()
            ->where('payment_method', 'cheque')
            ->where('cheque_status', 'pending')
            ->sum('amount');
        $paidAmount = (float) $sale->paid_amount;
        $dueAmount = round(max(0, (float) $sale->grand_total - $paidAmount - $pendingChequeAmount), 2);

        $status = 'cheque_pending';
        if ($pendingChequeAmount <= 0 && $dueAmount <= 0) {
            $status = 'paid';
        } elseif ($pendingChequeAmount <= 0 && $paidAmount > 0) {
            $status = 'partial';
        } elseif ($pendingChequeAmount <= 0) {
            $status = 'due';
        }

        $sale->update([
            'due_amount' => $dueAmount,
            'payment_status' => $status,
        ]);

        $dueDelta = round($dueAmount - $oldDueAmount, 2);
        if ($dueDelta !== 0.0 && $sale->customer) {
            $sale->customer->update([
                'due_balance' => round(max(0, (float) $sale->customer->due_balance + $dueDelta), 2),
            ]);
        }
    }
}
