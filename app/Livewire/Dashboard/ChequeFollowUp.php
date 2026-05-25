<?php

namespace App\Livewire\Dashboard;

use App\Models\Payment;
use App\Services\ChequePaymentService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class ChequeFollowUp extends Component
{
    public function getActionableChequePayments(): Collection
    {
        return app(ChequePaymentService::class)->actionablePendingCheques();
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
        $payment = Payment::query()->where('payment_method', 'cheque')->findOrFail($paymentId);

        app(ChequePaymentService::class)->markReturned($payment);

        Flux::toast(variant: 'danger', text: __('Cheque returned. Invoice remains due.'));

        $this->dispatch('cheque-updated');
    }

    public function render(): View
    {
        return view('livewire.dashboard.cheque-follow-up');
    }
}
