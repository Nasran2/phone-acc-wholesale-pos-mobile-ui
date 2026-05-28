<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
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

            $purchase = $payment->paymentable;
            if ($purchase instanceof Purchase) {
                $this->applyPassedChequeToPurchase($purchase, (float) $payment->amount);
            }

            if ($payment->cheque_type === 'party' && $payment->sourcePayment?->cheque_status === 'pending') {
                $sourceSale = $payment->sourcePayment->paymentable;

                if ($sourceSale instanceof Sale) {
                    $this->applyPassedChequeToSale($sourceSale, (float) $payment->sourcePayment->amount);
                }

                $payment->sourcePayment->update([
                    'cheque_status' => 'passed',
                    'cheque_processed_at' => now(),
                ]);

                if ($sourceSale instanceof Sale) {
                    $this->syncSaleStatus($sourceSale);
                }
            }

            $supplier = $payment->paymentable;
            if ($supplier instanceof Supplier) {
                // Supplier due balance already reduced on cheque creation; no extra sync needed.
            }

            $payment->update([
                'cheque_status' => 'passed',
                'cheque_processed_at' => now(),
            ]);

            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            if ($purchase instanceof Purchase) {
                $this->syncPurchaseStatus($purchase);
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

            $purchase = $payment->paymentable;
            if ($purchase instanceof Purchase) {
                $this->syncPurchaseStatus($purchase);
            }

            if ($payment->cheque_type === 'party' && $payment->sourcePayment?->cheque_status === 'pending') {
                $sourceSale = $payment->sourcePayment->paymentable;

                $payment->sourcePayment->update([
                    'cheque_status' => 'returned',
                    'cheque_processed_at' => now(),
                ]);

                if ($sourceSale instanceof Sale) {
                    $this->syncSaleStatus($sourceSale);
                }
            }

            $supplier = $payment->paymentable;
            if ($supplier instanceof Supplier) {
                $supplier->update([
                    'due_balance' => round(max(0, (float) $supplier->due_balance + (float) $payment->amount), 2),
                ]);
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

    public function autoPassOverdueOwnSupplierCheques(?CarbonInterface $today = null): int
    {
        $today ??= today();
        $cutoff = $today->copy()->subDays(3)->toDateString();
        $processed = 0;

        Payment::query()
            ->pendingCheque()
            ->whereIn('paymentable_type', [Purchase::class, Supplier::class])
            ->where('cheque_type', 'own')
            ->whereDate('cheque_date', '<=', $cutoff)
            ->with('paymentable')
            ->orderBy('cheque_date')
            ->each(function (Payment $payment) use (&$processed): void {
                $this->pass($payment);
                $processed++;
            });

        return $processed;
    }

    public function actionablePendingCheques(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        $this->autoPassOverduePendingCheques($today);
        $this->autoPassOverdueOwnSupplierCheques($today);

        $customerCheques = Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDoesntHave('issuedPayments', fn ($query) => $query->where('cheque_status', 'pending'))
            ->whereDate('cheque_date', '<=', $today->copy()->addDays(2)->toDateString())
            ->with('paymentable.customer')
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->get();

        $supplierCheques = Payment::query()
            ->pendingCheque()
            ->where(function ($query) use ($today): void {
                $query->where(function ($query) use ($today): void {
                    $query->where('paymentable_type', Purchase::class)
                        ->where('cheque_type', 'own')
                        ->whereDate('cheque_date', '<=', $today->copy()->addDays(3)->toDateString());
                })->orWhere(function ($query) use ($today): void {
                    $query->where('paymentable_type', Purchase::class)
                        ->where('cheque_type', 'party')
                        ->whereDate('cheque_date', '<=', $today->copy()->addDays(2)->toDateString());
                })->orWhere(function ($query) use ($today): void {
                    $query->where('paymentable_type', Supplier::class)
                        ->where('cheque_type', 'own')
                        ->whereDate('cheque_date', '<=', $today->copy()->addDays(3)->toDateString());
                })->orWhere(function ($query) use ($today): void {
                    $query->where('paymentable_type', Supplier::class)
                        ->where('cheque_type', 'party')
                        ->whereDate('cheque_date', '<=', $today->copy()->addDays(2)->toDateString());
                });
            })
            ->with(['paymentable', 'sourcePayment.paymentable.customer', 'partyCustomer'])
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->get();

        return $customerCheques
            ->concat($supplierCheques)
            ->sortBy([
                ['cheque_date', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
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

    private function applyPassedChequeToPurchase(Purchase $purchase, float $amount): void
    {
        $purchase->refresh();

        $remainingBalance = max(0, (float) $purchase->grand_total - (float) $purchase->paid_amount);
        $acceptedAmount = min($amount, $remainingBalance);

        if ($acceptedAmount <= 0) {
            return;
        }

        $purchase->update([
            'paid_amount' => round((float) $purchase->paid_amount + $acceptedAmount, 2),
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

    private function syncPurchaseStatus(Purchase $purchase): void
    {
        $purchase->refresh();
        $oldDueAmount = (float) $purchase->due_amount;
        $pendingChequeAmount = (float) $purchase->payments()
            ->where('payment_method', 'cheque')
            ->where('cheque_status', 'pending')
            ->sum('amount');
        $paidAmount = (float) $purchase->paid_amount;
        $dueAmount = round(max(0, (float) $purchase->grand_total - $paidAmount - $pendingChequeAmount), 2);

        $status = 'cheque_pending';
        if ($pendingChequeAmount <= 0 && $dueAmount <= 0) {
            $status = 'paid';
        } elseif ($pendingChequeAmount <= 0 && $paidAmount > 0) {
            $status = 'partial';
        } elseif ($pendingChequeAmount <= 0) {
            $status = 'due';
        }

        $purchase->update([
            'due_amount' => $dueAmount,
            'payment_status' => $status,
        ]);

        $dueDelta = round($dueAmount - $oldDueAmount, 2);
        if ($dueDelta !== 0.0 && $purchase->supplier) {
            $purchase->supplier->update([
                'due_balance' => round(max(0, (float) $purchase->supplier->due_balance + $dueDelta), 2),
            ]);
        }
    }
}
