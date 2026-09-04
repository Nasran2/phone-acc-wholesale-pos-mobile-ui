<?php

use App\Models\Payment;
use App\Services\ChequePaymentService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Cheque Report')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = 'all';

    public function updatingSearch()
    {
        $this->resetPage();
    }
    
    public function updatingStatus()
    {
        $this->resetPage();
    }

    public ?int $editPaymentId = null;
    public string $editAmount = '';
    public string $editChequeNo = '';
    public string $editChequeBank = '';
    public string $editChequeDate = '';

    public function editCheque(int $paymentId)
    {
        $this->editPaymentId = $paymentId;
        $payment = Payment::query()->findOrFail($paymentId);
        
        $this->editAmount = $payment->amount;
        $this->editChequeNo = $payment->cheque_no ?: ($payment->reference ?: '');
        $this->editChequeBank = $payment->cheque_bank ?: '';
        $this->editChequeDate = $payment->cheque_date ? $payment->cheque_date->format('Y-m-d') : '';
        
        $this->resetValidation();
        Flux::modal('edit-cheque')->show();
    }

    public function updateCheque()
    {
        $this->validate([
            'editAmount' => 'required|numeric|min:0',
            'editChequeNo' => 'required|string|max:255',
            'editChequeBank' => 'required|string|max:255',
            'editChequeDate' => 'required|date',
        ]);

        $payment = Payment::query()->findOrFail($this->editPaymentId);
        
        $data = [
            'amount' => $this->editAmount,
            'cheque_no' => $this->editChequeNo,
            'reference' => $this->editChequeNo,
            'cheque_bank' => $this->editChequeBank,
            'cheque_date' => $this->editChequeDate,
        ];

        $payment->update($data);

        if ($payment->sourcePayment) {
            $payment->sourcePayment->update($data);
        }

        Flux::modal('edit-cheque')->close();
        Flux::toast(variant: 'success', text: __('Cheque details updated successfully.'));
    }

    public function markAsPassed(int $paymentId, ChequePaymentService $service)
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $service->pass($payment);
        Flux::toast(variant: 'success', text: __('Cheque marked as passed.'));
    }

    public function markAsReturned(int $paymentId, ChequePaymentService $service)
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $service->markReturned($payment);
        Flux::toast(variant: 'danger', text: __('Cheque marked as returned.'));
    }

    public function markAsPending(int $paymentId, ChequePaymentService $service)
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $service->revertToPending($payment);
        Flux::toast(variant: 'success', text: __('Cheque reverted to pending status.'));
    }

    #[Computed]
    public function cheques()
    {
        $query = Payment::query()
            ->where('payment_method', 'cheque')
            ->whereDoesntHave('issuedPayments')
            ->with(['paymentable', 'sourcePayment.paymentable', 'partyCustomer']);

        if ($this->status !== 'all') {
            $query->where('cheque_status', $this->status);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('cheque_no', 'like', '%' . $this->search . '%')
                  ->orWhere('cheque_bank', 'like', '%' . $this->search . '%')
                  ->orWhere('reference', 'like', '%' . $this->search . '%');
            });
        }

        return $query->orderBy('cheque_date', 'desc')->orderBy('id', 'desc')->paginate(15);
    }
    
    public function getCustomerName($payment)
    {
        if ($payment->partyCustomer) {
            return $payment->partyCustomer->name;
        }
        if ($payment->sourcePayment) {
            return $this->getCustomerName($payment->sourcePayment);
        }
        if ($payment->paymentable instanceof \App\Models\Sale) {
            return $payment->paymentable->customer?->name;
        }
        if ($payment->paymentable instanceof \App\Models\Customer) {
            return $payment->paymentable->name;
        }
        return '-';
    }

    public function getSupplierName($payment)
    {
        if ($payment->paymentable instanceof \App\Models\Purchase) {
            return $payment->paymentable->supplier?->name;
        }
        if ($payment->paymentable instanceof \App\Models\Supplier) {
            return $payment->paymentable->name;
        }
        return '-';
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Cheque Report') }}</h1>
        <p class="text-sm text-zinc-500">{{ __('Monitor and manage all incoming and outgoing cheques, and update their statuses.') }}</p>
    </div>

    <!-- Filters -->
    <div class="app-card p-4 grid gap-4 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search cheque no or bank..." />

        <flux:select wire:model.live="status" placeholder="All Statuses">
            <option value="all">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="passed">Passed</option>
            <option value="returned">Returned</option>
        </flux:select>
    </div>

    <!-- Table -->
    <div class="app-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                    <tr>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Cheque Details') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Amount') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Customer') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Supplier') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Status') }}</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white">
                    @forelse ($this->cheques as $pm)
                        <tr class="hover:bg-zinc-50/50" wire:key="cheque-row-{{ $pm->id }}">
                            <td class="px-4 py-3">
                                <span class="font-bold text-zinc-900 block">{{ $pm->cheque_no ?: ($pm->reference ?: 'N/A') }}</span>
                                <span class="text-xs text-zinc-500 block">{{ $pm->cheque_bank ?: 'Bank N/A' }}</span>
                                <span class="text-xs text-zinc-400 block">{{ $pm->cheque_date ? $pm->cheque_date->format('Y-m-d') : 'No Date' }}</span>
                            </td>
                            <td class="px-4 py-3 font-semibold text-zinc-900">Rs {{ number_format($pm->amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $this->getCustomerName($pm) }}</td>
                            <td class="px-4 py-3">{{ $this->getSupplierName($pm) }}</td>
                            <td class="px-4 py-3">
                                @if ($pm->cheque_status === 'passed')
                                    <flux:badge size="sm" color="emerald">Passed</flux:badge>
                                @elseif ($pm->cheque_status === 'returned')
                                    <flux:badge size="sm" color="rose">Returned</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">Pending</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:dropdown>
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:navmenu>
                                        <flux:navmenu.item wire:click="editCheque({{ $pm->id }})" icon="pencil" class="text-zinc-600">Edit Details</flux:navmenu.item>
                                        @if ($pm->cheque_status !== 'passed')
                                            <flux:navmenu.item wire:click="markAsPassed({{ $pm->id }})" icon="check-circle" class="text-emerald-600">Mark Passed</flux:navmenu.item>
                                        @endif
                                        @if ($pm->cheque_status !== 'returned')
                                            <flux:navmenu.item wire:click="markAsReturned({{ $pm->id }})" icon="x-circle" class="text-rose-600">Mark Returned</flux:navmenu.item>
                                        @endif
                                        @if ($pm->cheque_status !== 'pending')
                                            <flux:navmenu.item wire:click="markAsPending({{ $pm->id }})" icon="clock" class="text-amber-600">Revert to Pending</flux:navmenu.item>
                                        @endif
                                    </flux:navmenu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-400">{{ __('No cheques found matching criteria.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-100 p-4">
            {{ $this->cheques->links() }}
        </div>
    </div>

    <!-- Edit Cheque Modal -->
    <flux:modal name="edit-cheque" class="md:w-96">
        <div class="space-y-6">
            <div>
                <h2 class="text-lg font-bold text-zinc-900">Edit Cheque Details</h2>
                <p class="text-sm text-zinc-500">Update the cheque information below.</p>
            </div>
            
            <div class="space-y-4">
                <flux:input wire:model="editAmount" label="Amount" type="number" step="0.01" />
                <flux:input wire:model="editChequeNo" label="Cheque No / Reference" />
                <flux:input wire:model="editChequeBank" label="Bank Name" />
                <flux:input wire:model="editChequeDate" label="Cheque Date" type="date" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="updateCheque" variant="primary">Save Changes</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
