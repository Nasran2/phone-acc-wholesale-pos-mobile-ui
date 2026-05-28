<?php

namespace App\Livewire\Dashboard;

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ChequePaymentService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ChequeFollowUp extends Component
{
    public bool $returnResolutionModalOpen = false;

    public ?int $returnedSupplierPaymentId = null;

    public string $settlementMethod = 'pay_later';

    public string $settlementReference = '';

    public string $settlementDate = '';

    public string $partyChequeSearch = '';

    public ?int $replacementPartyChequePaymentId = null;

    public function mount(): void
    {
        $this->settlementDate = today()->toDateString();
    }

    public function getActionableChequePayments(): Collection
    {
        return app(ChequePaymentService::class)->actionablePendingCheques();
    }

    public function getReplacementPartyChequesProperty(): Collection
    {
        if (blank($this->partyChequeSearch)) {
            return new Collection;
        }

        return Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->whereDoesntHave('issuedPayments', fn ($query) => $query->where('cheque_status', 'pending'))
            ->where(function ($query): void {
                $query->where('cheque_no', 'like', '%'.$this->partyChequeSearch.'%')
                    ->orWhere('reference', 'like', '%'.$this->partyChequeSearch.'%');
            })
            ->with('paymentable.customer')
            ->limit(5)
            ->get();
    }

    public function selectReplacementPartyCheque(int $paymentId): void
    {
        $payment = Payment::query()
            ->pendingCheque()
            ->where('paymentable_type', Sale::class)
            ->with('paymentable.customer')
            ->findOrFail($paymentId);

        $this->replacementPartyChequePaymentId = $payment->id;
        $this->partyChequeSearch = $payment->cheque_no ?: (string) $payment->reference;
    }

    public function passChequePayment(int $paymentId): void
    {
        $payment = Payment::query()->where('payment_method', 'cheque')->findOrFail($paymentId);

        app(ChequePaymentService::class)->pass($payment);

        Flux::toast(variant: 'success', text: __('Cheque marked as passed and payment updated.'));

        $this->dispatch('cheque-updated');
    }

    public function returnChequePayment(int $paymentId): void
    {
        $payment = Payment::query()->where('payment_method', 'cheque')->with('paymentable')->findOrFail($paymentId);

        app(ChequePaymentService::class)->markReturned($payment);

        Flux::toast(variant: 'danger', text: __('Cheque returned. Invoice remains due.'));

        if ($payment->paymentable instanceof Purchase) {
            $this->returnedSupplierPaymentId = $payment->id;
            $this->settlementMethod = 'pay_later';
            $this->settlementReference = '';
            $this->partyChequeSearch = '';
            $this->replacementPartyChequePaymentId = null;
            $this->returnResolutionModalOpen = true;
        }

        $this->dispatch('cheque-updated');
    }

    public function settleReturnedSupplierCheque(): void
    {
        $this->validate([
            'returnedSupplierPaymentId' => 'required|exists:payments,id',
            'settlementMethod' => 'required|in:cash,bank_transfer,party_cheque,pay_later',
            'settlementDate' => 'required|date',
            'settlementReference' => 'nullable|string|max:120',
            'replacementPartyChequePaymentId' => 'required_if:settlementMethod,party_cheque|nullable|exists:payments,id',
        ]);

        $returnedPayment = Payment::query()
            ->with('paymentable.supplier')
            ->where('payment_method', 'cheque')
            ->findOrFail($this->returnedSupplierPaymentId);

        if (! $returnedPayment->paymentable instanceof Purchase || $this->settlementMethod === 'pay_later') {
            $this->returnResolutionModalOpen = false;
            Flux::toast(variant: 'success', text: __('Supplier balance remains due for later payment.'));

            return;
        }

        DB::transaction(function () use ($returnedPayment): void {
            $purchase = $returnedPayment->paymentable;
            $amount = min((float) $returnedPayment->amount, (float) $purchase->due_amount);

            if ($this->settlementMethod === 'party_cheque') {
                $replacementCheque = Payment::query()
                    ->pendingCheque()
                    ->where('paymentable_type', Sale::class)
                    ->with('paymentable.customer')
                    ->findOrFail($this->replacementPartyChequePaymentId);

                $purchase->payments()->create([
                    'amount' => min((float) $replacementCheque->amount, (float) $purchase->due_amount),
                    'payment_method' => 'cheque',
                    'date' => $this->settlementDate,
                    'reference' => $replacementCheque->cheque_no ?: $replacementCheque->reference,
                    'cheque_bank' => $replacementCheque->cheque_bank,
                    'cheque_no' => $replacementCheque->cheque_no,
                    'cheque_date' => $replacementCheque->cheque_date,
                    'cheque_status' => 'pending',
                    'cheque_type' => 'party',
                    'source_payment_id' => $replacementCheque->id,
                    'party_customer_id' => $replacementCheque->paymentable?->customer_id,
                    'notes' => 'Replacement party cheque after supplier cheque return.',
                ]);

                $purchase->decrement('due_amount', min((float) $replacementCheque->amount, (float) $purchase->due_amount));
                $purchase->update(['payment_status' => 'cheque_pending']);
                $purchase->supplier?->decrement('due_balance', min((float) $replacementCheque->amount, (float) $purchase->supplier->due_balance));

                return;
            }

            $purchase->payments()->create([
                'amount' => $amount,
                'payment_method' => $this->settlementMethod,
                'date' => $this->settlementDate,
                'reference' => $this->settlementReference,
                'notes' => 'Replacement supplier payment after cheque return.',
            ]);

            $purchase->increment('paid_amount', $amount);
            $purchase->decrement('due_amount', $amount);
            $purchase->update(['payment_status' => $purchase->refresh()->due_amount > 0 ? 'partial' : 'paid']);
            $purchase->supplier?->decrement('due_balance', min($amount, (float) $purchase->supplier->due_balance));
        });

        $this->returnResolutionModalOpen = false;
        Flux::toast(variant: 'success', text: __('Replacement supplier payment recorded.'));
        $this->dispatch('cheque-updated');
    }

    public function render(): View
    {
        return view('livewire.dashboard.cheque-follow-up');
    }
}
