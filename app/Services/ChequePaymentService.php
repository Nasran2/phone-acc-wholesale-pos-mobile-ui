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
                $this->applyChequeAmountToSale($sale, (float) $payment->amount);
            }

            $payment->update([
                'cheque_status' => 'passed',
                'cheque_processed_at' => now(),
            ]);

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

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            $payment->update([
                'cheque_status' => 'returned',
                'cheque_processed_at' => now(),
            ]);

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

    private function applyChequeAmountToSale(Sale $sale, float $amount): void
    {
        $sale->refresh();

        $remainingDue = max(0, (float) $sale->due_amount);
        $acceptedAmount = min($amount, $remainingDue);

        if ($acceptedAmount <= 0) {
            $this->syncSaleStatus($sale);

            return;
        }

        $sale->update([
            'paid_amount' => round((float) $sale->paid_amount + $acceptedAmount, 2),
            'due_amount' => round(max(0, $remainingDue - $acceptedAmount), 2),
        ]);

        if ($sale->customer) {
            $sale->customer->update([
                'due_balance' => round(max(0, (float) $sale->customer->due_balance - $acceptedAmount), 2),
            ]);
        }

        $this->syncSaleStatus($sale);
    }

    private function syncSaleStatus(Sale $sale): void
    {
        $sale->refresh();
        $dueAmount = (float) $sale->due_amount;
        $paidAmount = (float) $sale->paid_amount;

        $status = 'due';
        if ($dueAmount <= 0) {
            $status = 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partial';
        }

        $sale->update(['payment_status' => $status]);
    }
}
