<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChequePaymentService
{
    public function __construct(private SmsNotificationService $smsNotificationService) {}

    public function assignSupplier(Payment $payment, int $supplierId): Payment
    {
        return DB::transaction(function () use ($payment, $supplierId): Payment {
            $payment = $this->lockCheque($payment);
            $owner = $payment->paymentable;
            if ($payment->payment_method !== 'cheque' || ! ($owner instanceof Sale || $owner instanceof Customer || $owner instanceof Supplier)) {
                throw ValidationException::withMessages(['assignedSupplierId' => __('Only customer cheques and supplier account cheques can be assigned here. Invoice cheques belong to their purchase supplier.')]);
            }
            if ($owner instanceof Supplier && $owner->id === $supplierId) {
                return $payment;
            }
            if (! in_array($payment->cheque_status, ['pending', 'passed', 'returned'], true) || (float) $payment->amount <= 0) {
                throw ValidationException::withMessages(['assignedSupplierId' => __('This cheque has an invalid status or amount.')]);
            }
            if (! $owner instanceof Supplier && $payment->issuedPayments()->exists()) {
                throw ValidationException::withMessages(['assignedSupplierId' => __('This cheque has already been issued. Refresh the report and edit the supplier cheque instead.')]);
            }

            $suppliers = Supplier::withTrashed()->whereIn('id', [$supplierId, $owner instanceof Supplier ? $owner->id : $supplierId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $newSupplier = $suppliers->get($supplierId);
            if (! $newSupplier || $newSupplier->trashed()) {
                throw ValidationException::withMessages(['assignedSupplierId' => __('Please choose an active supplier.')]);
            }
            $deduction = $payment->cheque_status === 'passed' ? (float) $payment->amount : 0.0;
            if ($deduction > 0 && $deduction > (float) $newSupplier->due_balance) {
                throw ValidationException::withMessages(['assignedSupplierId' => __('The cheque amount exceeds this supplier’s outstanding due.')]);
            }

            $oldSupplierName = $owner instanceof Supplier ? $owner->name : __('None');
            if ($owner instanceof Supplier) {
                $oldDeduction = $payment->supplier_deduction_amount !== null
                    ? (float) $payment->supplier_deduction_amount
                    : ($payment->cheque_status === 'returned' ? 0.0 : (float) $payment->amount);
                $suppliers[$owner->id]->increment('due_balance', $oldDeduction);
                $payment->paymentable()->associate($newSupplier);
                $payment->supplier_deduction_amount = $deduction;
                $payment->save();
            } else {
                $sourcePayment = $payment;
                $payment = $newSupplier->payments()->create([
                    ...$sourcePayment->only(['amount', 'payment_method', 'date', 'reference', 'cheque_bank', 'cheque_no', 'cheque_date', 'cheque_status', 'cheque_processed_at']),
                    'cheque_type' => 'party', 'source_payment_id' => $sourcePayment->id,
                    'party_customer_id' => $owner instanceof Customer ? $owner->id : $owner->customer_id,
                    'supplier_deduction_amount' => $deduction,
                    'notes' => __('Customer cheque assigned to supplier; supplier due is reduced only when passed.'),
                ]);
            }
            if ($deduction > 0) {
                $newSupplier->decrement('due_balance', $deduction);
            }
            ActivityLogger::log('cheque_supplier_changed', __('Cheque :number (Rs :amount): supplier changed from :old to :new.', [
                'number' => $payment->cheque_no ?: $payment->reference, 'amount' => $payment->amount,
                'old' => $oldSupplierName, 'new' => $newSupplier->name,
            ]));

            return $payment->refresh();
        }, 3);
    }

    /** @param array{amount: string, cheque_no: string, reference: string, cheque_bank: string, cheque_date: string} $details */
    public function updateDetails(Payment $payment, array $details): Payment
    {
        return DB::transaction(function () use ($payment, $details): Payment {
            $payment = $this->lockCheque($payment);
            if ($payment->payment_method !== 'cheque') {
                throw ValidationException::withMessages(['editAmount' => __('Please select a cheque payment.')]);
            }
            if (round((float) $details['amount'], 2) !== (float) $payment->amount) {
                throw ValidationException::withMessages(['editAmount' => __('The amount cannot be changed here because it affects account balances. Correct it through the original payment or invoice.')]);
            }
            $payment->update($details);
            $payment->sourcePayment?->update(Arr::except($details, ['amount']));

            return $payment->refresh();
        });
    }

    public function pass(Payment $payment): Payment
    {
        $notifyPaymentIds = [];

        $passedPayment = DB::transaction(function () use ($payment, &$notifyPaymentIds) {
            $payment = $this->lockCheque($payment);

            if ($payment->payment_method === 'cheque' && $payment->cheque_status === 'returned') {
                $payment = $this->revertToPending($payment);
            }

            if ($payment->payment_method !== 'cheque' || $payment->cheque_status !== 'pending') {
                return $payment;
            }

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->applyPassedChequeToSale($sale, (float) $payment->amount);
            }

            $customer = $payment->paymentable;
            if ($customer instanceof Customer) {
                // Customer due balance already reduced on cheque creation; no extra sync needed.
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
                $notifyPaymentIds[] = $payment->sourcePayment->id;

                if ($sourceSale instanceof Sale) {
                    $this->syncSaleStatus($sourceSale);
                }
            }

            $supplier = $payment->paymentable;
            if ($supplier instanceof Supplier) {
                // Supplier due balance already reduced on cheque creation; no extra sync needed.
            }

            $this->syncAssignedSupplierDeduction($payment, 'passed');
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

            $notifyPaymentIds[] = $payment->id;

            return $payment->refresh();
        });

        Payment::query()
            ->whereIn('id', array_unique($notifyPaymentIds))
            ->with('paymentable')
            ->get()
            ->each(function (Payment $payment): void {
                $this->smsNotificationService->notifyChequePassed($payment);
            });

        return $passedPayment;
    }

    public function markReturned(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = $this->lockCheque($payment);

            if ($payment->payment_method === 'cheque' && $payment->cheque_status === 'passed') {
                $payment = $this->revertToPending($payment);
            }

            if ($payment->payment_method !== 'cheque' || $payment->cheque_status !== 'pending') {
                return $payment;
            }

            $this->syncAssignedSupplierDeduction($payment, 'returned');
            $payment->update([
                'cheque_status' => 'returned',
                'cheque_processed_at' => now(),
            ]);

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            $customer = $payment->paymentable;
            if ($customer instanceof Customer) {
                $customer->update([
                    'due_balance' => round(max(0, (float) $customer->due_balance + (float) $payment->amount), 2),
                ]);
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

                if ($sourceSale instanceof Customer) {
                    $sourceSale->update([
                        'due_balance' => round(max(0, (float) $sourceSale->due_balance + (float) $payment->sourcePayment->amount), 2),
                    ]);
                }
            }

            $supplier = $payment->paymentable;
            if ($supplier instanceof Supplier && $payment->supplier_deduction_amount === null) {
                $supplier->update([
                    'due_balance' => round(max(0, (float) $supplier->due_balance + (float) $payment->amount), 2),
                ]);
            }

            return $payment->refresh();
        });
    }

    public function revertToPending(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = $this->lockCheque($payment);

            if ($payment->payment_method !== 'cheque' || $payment->cheque_status === 'pending') {
                return $payment;
            }

            $oldStatus = $payment->cheque_status;
            $this->syncAssignedSupplierDeduction($payment, 'pending');

            if ($oldStatus === 'passed') {
                $sale = $payment->paymentable;
                if ($sale instanceof Sale) {
                    $sale->update([
                        'paid_amount' => round(max(0, (float) $sale->paid_amount - (float) $payment->amount), 2),
                    ]);
                }

                $purchase = $payment->paymentable;
                if ($purchase instanceof Purchase) {
                    $purchase->update([
                        'paid_amount' => round(max(0, (float) $purchase->paid_amount - (float) $payment->amount), 2),
                    ]);
                }

                if ($payment->cheque_type === 'party' && $payment->sourcePayment) {
                    $sourceSale = $payment->sourcePayment->paymentable;
                    if ($sourceSale instanceof Sale) {
                        $sourceSale->update([
                            'paid_amount' => round(max(0, (float) $sourceSale->paid_amount - (float) $payment->sourcePayment->amount), 2),
                        ]);
                    }
                    $payment->sourcePayment->update([
                        'cheque_status' => 'pending',
                        'cheque_processed_at' => null,
                    ]);
                    if ($sourceSale instanceof Sale) {
                        $this->syncSaleStatus($sourceSale);
                    }
                }
            }

            if ($oldStatus === 'returned') {
                $customer = $payment->paymentable;
                if ($customer instanceof Customer) {
                    $customer->update([
                        'due_balance' => round(max(0, (float) $customer->due_balance - (float) $payment->amount), 2),
                    ]);
                }
                $supplier = $payment->paymentable;
                if ($supplier instanceof Supplier && $payment->supplier_deduction_amount === null) {
                    $supplier->update([
                        'due_balance' => round(max(0, (float) $supplier->due_balance - (float) $payment->amount), 2),
                    ]);
                }

                if ($payment->cheque_type === 'party' && $payment->sourcePayment) {
                    $payment->sourcePayment->update([
                        'cheque_status' => 'pending',
                        'cheque_processed_at' => null,
                    ]);
                    $sourceSale = $payment->sourcePayment->paymentable;
                    if ($sourceSale instanceof Sale) {
                        $this->syncSaleStatus($sourceSale);
                    }
                    if ($sourceSale instanceof Customer) {
                        $sourceSale->update([
                            'due_balance' => round(max(0, (float) $sourceSale->due_balance - (float) $payment->sourcePayment->amount), 2),
                        ]);
                    }
                }
            }

            $payment->update([
                'cheque_status' => 'pending',
                'cheque_processed_at' => null,
            ]);

            $sale = $payment->paymentable;
            if ($sale instanceof Sale) {
                $this->syncSaleStatus($sale);
            }

            $purchase = $payment->paymentable;
            if ($purchase instanceof Purchase) {
                $this->syncPurchaseStatus($purchase);
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
            ->whereIn('paymentable_type', [Sale::class, Customer::class])
            ->whereDate('cheque_date', '<=', $cutoff)
            ->with('paymentable')
            ->orderBy('cheque_date')
            ->each(function (Payment $payment) use (&$processed) {
                try {
                    $passed = $this->pass($payment);
                    $processed += $passed->cheque_status === 'passed' ? 1 : 0;
                } catch (ValidationException) {
                    return;
                }
            });

        return $processed;
    }

    private function lockCheque(Payment $payment): Payment
    {
        $sourceId = Payment::query()->whereKey($payment->id)->value('source_payment_id');
        if ($sourceId) {
            Payment::query()->lockForUpdate()->findOrFail($sourceId);
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        if (! $payment->source_payment_id) {
            $assignedPayment = $payment->issuedPayments()->whereNotNull('supplier_deduction_amount')->lockForUpdate()->first();
            if ($assignedPayment) {
                return $assignedPayment;
            }
        }

        return $payment;
    }

    private function syncAssignedSupplierDeduction(Payment $payment, string $status): void
    {
        if ($payment->supplier_deduction_amount === null || ! $payment->paymentable instanceof Supplier) {
            return;
        }
        $supplier = Supplier::withTrashed()->lockForUpdate()->findOrFail($payment->paymentable_id);
        $deduction = $status === 'passed' ? (float) $payment->amount : 0.0;
        $difference = round($deduction - (float) $payment->supplier_deduction_amount, 2);
        if ($difference > (float) $supplier->due_balance && $difference > 0) {
            throw ValidationException::withMessages(['cheque' => __('The cheque amount exceeds the supplier’s outstanding due. Change the supplier or correct their balance before passing it.')]);
        }
        if ($difference !== 0.0) {
            $supplier->decrement('due_balance', $difference);
        }
        $payment->update(['supplier_deduction_amount' => $deduction]);
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
                try {
                    $passed = $this->pass($payment);
                    $processed += $passed->cheque_status === 'passed' ? 1 : 0;
                } catch (ValidationException) {
                    return;
                }
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
            ->whereIn('paymentable_type', [Sale::class, Customer::class])
            ->whereDoesntHave('issuedPayments', fn ($query) => $query->where('cheque_status', 'pending'))
            ->whereDate('cheque_date', '<=', $today->copy()->addDays(2)->toDateString())
            ->with('paymentable')
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
            ->with(['paymentable', 'sourcePayment.paymentable', 'partyCustomer'])
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
        $dueAmount = round(max(0, (float) $sale->grand_total - $paidAmount - $pendingChequeAmount - app(SaleReturnService::class)->dueReductionFor($sale)), 2);

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
        $dueAmount = round(max(0, (float) $purchase->grand_total - $paidAmount - $pendingChequeAmount - PurchaseReturn::dueReductionFor($purchase->id)), 2);

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
