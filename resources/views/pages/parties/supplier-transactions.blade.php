<?php

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Supplier Transactions')] class extends Component
{
    public string $search = '';

    public ?int $selectedSupplierId = null;

    public ?int $selectedPurchaseId = null;

    public ?string $selectedChequeStatus = null;

    public string $transactionType = 'all';

    public string $paymentMethod = 'all';

    public string $chequeStatus = 'all';

    protected $queryString = [
        'search' => ['except' => ''],
        'selectedSupplierId' => ['as' => 'supplier_id', 'except' => null],
        'transactionType' => ['except' => 'all'],
        'paymentMethod' => ['except' => 'all'],
        'chequeStatus' => ['except' => 'all'],
    ];

    public function selectSupplier(int $supplierId): void
    {
        $this->selectedSupplierId = $supplierId;
        $this->selectedPurchaseId = null;
        $this->selectedChequeStatus = null;
    }

    public function backToSuppliers(): void
    {
        $this->selectedSupplierId = null;
        $this->selectedPurchaseId = null;
        $this->selectedChequeStatus = null;
    }

    public function viewPurchaseDetail(int $purchaseId): void
    {
        $this->selectedPurchaseId = $purchaseId;
    }

    public function closePurchaseDetail(): void
    {
        $this->selectedPurchaseId = null;
    }

    public function showChequeList(?string $status = null): void
    {
        $this->selectedChequeStatus = $status ?: 'all';
    }

    public function closeChequeList(): void
    {
        $this->selectedChequeStatus = null;
    }

    public function invoiceTone(string $invoiceNo): string
    {
        $tones = [
            'bg-violet-100/80 hover:bg-violet-100 border-violet-200',
            'bg-sky-100/80 hover:bg-sky-100 border-sky-200',
            'bg-emerald-100/80 hover:bg-emerald-100 border-emerald-200',
            'bg-amber-100/80 hover:bg-amber-100 border-amber-200',
            'bg-rose-100/80 hover:bg-rose-100 border-rose-200',
            'bg-fuchsia-100/80 hover:bg-fuchsia-100 border-fuchsia-200',
            'bg-cyan-100/80 hover:bg-cyan-100 border-cyan-200',
            'bg-orange-100/80 hover:bg-orange-100 border-orange-200',
        ];

        return $tones[abs(crc32($invoiceNo)) % count($tones)];
    }

    public function transactionTone(string $invoiceNo, Collection $transactions, string $singleTone = ''): string
    {
        return $transactions->where('invoice_no', $invoiceNo)->count() > 1
            ? $this->invoiceTone($invoiceNo)
            : $singleTone;
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::query()
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);

                $query->where(function ($supplierQuery) use ($search): void {
                    $supplierQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedSupplier(): ?Supplier
    {
        return $this->selectedSupplierId
            ? Supplier::query()->find($this->selectedSupplierId)
            : null;
    }

    #[Computed]
    public function selectedPurchase(): ?Purchase
    {
        return $this->selectedPurchaseId
            ? Purchase::query()->with(['supplier', 'items.product', 'payments.sourcePayment.paymentable.customer', 'payments.partyCustomer'])->findOrFail($this->selectedPurchaseId)
            : null;
    }

    #[Computed]
    public function supplierTransactions(): Collection
    {
        if (! $this->selectedSupplierId) {
            return collect();
        }

        $purchases = Purchase::query()
            ->with(['payments' => fn ($query) => $query->orderBy('date')->orderBy('id')])
            ->where('supplier_id', $this->selectedSupplierId)
            ->get();

        $purchasePayments = $purchases->flatMap(fn (Purchase $purchase) => $purchase->payments->map(fn (Payment $payment): array => [
            'type' => 'payment',
            'label' => __('Invoice Payment'),
            'date' => $payment->date,
            'invoice_no' => $purchase->invoice_no,
            'invoice_id' => $purchase->id,
            'description' => __('Payment made for purchase invoice'),
            'payment_method' => $payment->payment_method,
            'cheque_status' => $payment->cheque_status,
            'status' => $payment->cheque_status ?: __('paid'),
            'credit' => 0.00,
            'debit' => (float) $payment->amount,
            'pending_amount' => (float) $purchase->due_amount,
            'reference' => $payment->reference,
            'cheque_no' => $payment->cheque_no,
            'cheque_bank' => $payment->cheque_bank,
            'cheque_date' => $payment->cheque_date,
            'cheque_type' => $payment->cheque_type,
            'raw_date' => $payment->created_at,
        ]));

        $accountPayments = Payment::query()
            ->where('paymentable_type', Supplier::class)
            ->where('paymentable_id', $this->selectedSupplierId)
            ->get()
            ->map(fn (Payment $payment): array => [
                'type' => 'account_payment',
                'label' => __('Account Payment'),
                'date' => $payment->date,
                'invoice_no' => $payment->reference ?: __('Account Ledger'),
                'invoice_id' => null,
                'description' => __('Payment made directly on supplier account'),
                'payment_method' => $payment->payment_method,
                'cheque_status' => $payment->cheque_status,
                'status' => $payment->cheque_status ?: __('paid'),
                'credit' => 0.00,
                'debit' => (float) ($payment->supplier_deduction_amount ?? $payment->amount),
                'pending_amount' => null,
                'reference' => $payment->reference,
                'cheque_no' => $payment->cheque_no,
                'cheque_bank' => $payment->cheque_bank,
                'cheque_date' => $payment->cheque_date,
                'cheque_type' => $payment->cheque_type,
                'raw_date' => $payment->created_at,
            ]);

        $returns = PurchaseReturn::query()->where('supplier_id', $this->selectedSupplierId)
            ->with('replacementPurchase')->get()->map(fn (PurchaseReturn $return): array => [
                'type' => 'purchase_return', 'label' => __('Purchase return'), 'date' => $return->date,
                'invoice_no' => $return->invoice_no, 'invoice_id' => null,
                'description' => __('Cash received: Rs :cash · Old due reduced: Rs :due · Exchange credit: Rs :credit', [
                    'cash' => number_format((float) $return->refund_amount, 2),
                    'due' => number_format((float) $return->adjusted_amount, 2),
                    'credit' => number_format((float) $return->replacementPurchase?->return_credit_amount, 2),
                ]),
                'payment_method' => (float) $return->refund_amount > 0 ? 'cash' : 'return_credit',
                'cheque_status' => null, 'status' => 'paid', 'credit' => 0,
                'debit' => (float) $return->adjusted_amount + (float) $return->replacementPurchase?->return_credit_amount,
                'pending_amount' => $return->replacementPurchase?->due_amount,
                'reference' => null, 'cheque_no' => null, 'cheque_date' => null,
                'raw_date' => $return->created_at,
            ]);

        return $purchasePayments
            ->concat($accountPayments)
            ->concat($returns)
            ->filter(fn (array $row): bool => $this->matchesFilters($row))
            ->sortByDesc('raw_date')
            ->values();
    }

    #[Computed]
    public function chequeRows(): Collection
    {
        if (! $this->selectedSupplierId) {
            return collect();
        }

        $purchaseIds = Purchase::query()
            ->where('supplier_id', $this->selectedSupplierId)
            ->pluck('id');

        $purchaseCheques = Payment::query()
            ->where('paymentable_type', Purchase::class)
            ->whereIn('paymentable_id', $purchaseIds)
            ->where('payment_method', 'cheque')
            ->with('paymentable')
            ->get()
            ->map(function (Payment $payment): array {
                $purchase = $payment->paymentable;

                return [
                    'invoice_no' => $purchase?->invoice_no ?: __('Purchase Invoice'),
                    'invoice_id' => $purchase?->id,
                    'cheque_no' => $payment->cheque_no ?: $payment->reference ?: 'CHQ-' . $payment->id,
                    'cheque_bank' => $payment->cheque_bank,
                    'cheque_date' => $payment->cheque_date,
                    'status' => $payment->cheque_status ?: 'pending',
                    'amount' => (float) $payment->amount,
                    'date' => $payment->date,
                    'raw_date' => $payment->created_at,
                ];
            });

        $accountCheques = Payment::query()
            ->where('paymentable_type', Supplier::class)
            ->where('paymentable_id', $this->selectedSupplierId)
            ->where('payment_method', 'cheque')
            ->get()
            ->map(fn (Payment $payment): array => [
                'invoice_no' => $payment->reference ?: __('Account Ledger'),
                'invoice_id' => null,
                'cheque_no' => $payment->cheque_no ?: $payment->reference ?: 'CHQ-' . $payment->id,
                'cheque_bank' => $payment->cheque_bank,
                'cheque_date' => $payment->cheque_date,
                'status' => $payment->cheque_status ?: 'pending',
                'amount' => (float) $payment->amount,
                'date' => $payment->date,
                'raw_date' => $payment->created_at,
            ]);

        return $purchaseCheques
            ->concat($accountCheques)
            ->sortByDesc('raw_date')
            ->values();
    }

    /**
     * @return array{total:int,pending:int,passed:int,returned:int}
     */
    #[Computed]
    public function chequeSummary(): array
    {
        return [
            'total' => $this->chequeRows->count(),
            'pending' => $this->chequeRows->where('status', 'pending')->count(),
            'passed' => $this->chequeRows->where('status', 'passed')->count(),
            'returned' => $this->chequeRows->where('status', 'returned')->count(),
        ];
    }

    #[Computed]
    public function selectedChequeRows(): Collection
    {
        if (! $this->selectedChequeStatus || $this->selectedChequeStatus === 'all') {
            return $this->chequeRows;
        }

        return $this->chequeRows
            ->where('status', $this->selectedChequeStatus)
            ->values();
    }

    private function matchesFilters(array $row): bool
    {
        if ($this->transactionType !== 'all' && $row['type'] !== $this->transactionType) {
            return false;
        }

        if ($this->paymentMethod !== 'all' && ($row['payment_method'] ?? null) !== $this->paymentMethod) {
            return false;
        }

        if ($this->chequeStatus !== 'all' && ($row['cheque_status'] ?? null) !== $this->chequeStatus) {
            return false;
        }

        return true;
    }
};
?>

<div
    class="flex flex-col gap-6"
    x-data="{
        purchaseOpen: @entangle('selectedPurchaseId'),
        chequeOpen: @entangle('selectedChequeStatus'),
    }"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-zinc-950">{{ __('Supplier Transactions') }}</h1>
            <p class="text-sm text-zinc-500">{{ __('Select a supplier to review restock invoices, outgoing payments, cheque status, and payable balances.') }}</p>
        </div>
        <a href="{{ route('parties.suppliers') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-2.5 text-xs font-black text-zinc-700 shadow-sm">
            <flux:icon.truck class="size-4" />
            {{ __('Suppliers List') }}
        </a>
    </div>

    @if (! $this->selectedSupplier)
        <section class="app-card p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 flex-1 items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input wire:model.live.debounce.250ms="search" class="min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" placeholder="{{ __('Search suppliers...') }}" />
                </div>
                <span class="text-xs font-black uppercase tracking-wider text-zinc-400">{{ number_format($this->suppliers->count()) }} {{ __('suppliers') }}</span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->suppliers as $supplier)
                    <button type="button" wire:click="selectSupplier({{ $supplier->id }})" class="rounded-3xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-[0_18px_45px_rgba(124,58,237,0.10)]">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-violet-50 text-violet-600">
                                    <flux:icon.truck class="size-5" />
                                </div>
                                <p class="mt-3 truncate text-base font-black text-zinc-950">{{ $supplier->name }}</p>
                                <p class="mt-1 truncate text-xs font-semibold text-zinc-500">{{ $supplier->company_name ?: $supplier->phone ?: __('No company') }}</p>
                            </div>
                            <flux:icon.chevron-right class="mt-2 size-5 shrink-0 text-zinc-300" />
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <div class="rounded-2xl bg-zinc-50 p-3">
                                <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Due') }}</p>
                                <p class="mt-1 text-sm font-black {{ (float) $supplier->due_balance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                    Rs {{ number_format((float) $supplier->due_balance, 2) }}
                                </p>
                            </div>
                            <div class="rounded-2xl bg-zinc-50 p-3">
                                <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Opening') }}</p>
                                <p class="mt-1 text-sm font-black text-zinc-800">Rs {{ number_format((float) $supplier->opening_balance, 2) }}</p>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-zinc-400">{{ __('No suppliers found.') }}</div>
                @endforelse
            </div>
        </section>
    @else
        <section class="min-w-0">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" wire:click="backToSuppliers" class="inline-flex w-fit items-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-2 text-xs font-black text-zinc-700 shadow-sm">
                    <flux:icon.arrow-left class="size-4" />
                    {{ __('Suppliers') }}
                </button>
                <div class="text-left sm:text-right">
                    <p class="text-[10px] font-black uppercase tracking-wider text-violet-600">{{ __('Selected Supplier') }}</p>
                    <h2 class="font-display text-xl font-black text-zinc-950">{{ $this->selectedSupplier->name }}</h2>
                    <p class="text-xs font-semibold text-zinc-500">{{ $this->selectedSupplier->company_name ?: $this->selectedSupplier->phone }}</p>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="app-card p-4">
                    <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Pending Amount') }}</p>
                    <p class="mt-1 text-lg font-black text-rose-600">Rs {{ number_format((float) $this->selectedSupplier->due_balance, 2) }}</p>
                </div>
                <button type="button" wire:click="showChequeList" class="app-card p-4 text-left transition hover:border-violet-200">
                    <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Total Cheques') }}</p>
                    <p class="mt-1 text-lg font-black text-zinc-950">{{ number_format($this->chequeSummary['total']) }}</p>
                </button>
                <button type="button" wire:click="showChequeList('pending')" class="app-card p-4 text-left transition hover:border-amber-200">
                    <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Pending Cheques') }}</p>
                    <p class="mt-1 text-lg font-black text-amber-600">{{ number_format($this->chequeSummary['pending']) }}</p>
                </button>
                <div class="grid grid-cols-2 gap-3 sm:col-span-2 xl:col-span-1">
                    <button type="button" wire:click="showChequeList('passed')" class="rounded-3xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-emerald-200">
                        <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Passed') }}</p>
                        <p class="mt-1 text-lg font-black text-emerald-600">{{ number_format($this->chequeSummary['passed']) }}</p>
                    </button>
                    <button type="button" wire:click="showChequeList('returned')" class="rounded-3xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-rose-200">
                        <p class="text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ __('Returned') }}</p>
                        <p class="mt-1 text-lg font-black text-rose-600">{{ number_format($this->chequeSummary['returned']) }}</p>
                    </button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 rounded-3xl border border-zinc-200 bg-white p-4 sm:grid-cols-3">
                <select wire:model.live="transactionType" class="rounded-2xl border border-zinc-200 px-3 py-2 text-sm font-semibold outline-none">
                    <option value="all">{{ __('All transaction types') }}</option>
                    <option value="payment">{{ __('Invoice payments') }}</option>
                    <option value="account_payment">{{ __('Account payments') }}</option>
                    <option value="purchase_return">{{ __('Purchase returns') }}</option>
                </select>
                <select wire:model.live="paymentMethod" class="rounded-2xl border border-zinc-200 px-3 py-2 text-sm font-semibold outline-none">
                    <option value="all">{{ __('All payment methods') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="bank_transfer">{{ __('Bank transfer') }}</option>
                    <option value="qr">{{ __('QR') }}</option>
                    <option value="cheque">{{ __('Cheque') }}</option>
                </select>
                <select wire:model.live="chequeStatus" class="rounded-2xl border border-zinc-200 px-3 py-2 text-sm font-semibold outline-none">
                    <option value="all">{{ __('All cheque statuses') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="passed">{{ __('Passed') }}</option>
                    <option value="returned">{{ __('Returned') }}</option>
                </select>
            </div>

            <div class="mt-4 overflow-hidden rounded-3xl border border-zinc-200 bg-white">
                <div class="hidden overflow-x-auto lg:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-100 bg-zinc-50 text-[10px] font-black uppercase tracking-wider text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">{{ __('Date') }}</th>
                                <th class="px-4 py-3">{{ __('Type') }}</th>
                                <th class="px-4 py-3">{{ __('Invoice No') }}</th>
                                <th class="px-4 py-3">{{ __('Method') }}</th>
                                <th class="px-4 py-3">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Invoice') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Paid') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Pending') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/80">
                            @forelse ($this->supplierTransactions as $row)
                                @php($invoiceTone = $this->transactionTone((string) $row['invoice_no'], $this->supplierTransactions))
                                <tr class="{{ $invoiceTone }}" wire:key="supplier-transaction-row-{{ $row['type'] }}-{{ $row['invoice_no'] }}-{{ $loop->index }}">
                                    <td class="px-4 py-3 font-semibold text-zinc-600">{{ $row['date']?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3 font-bold text-zinc-900">{{ $row['label'] }}</td>
                                    <td class="px-4 py-3">
                                        @if ($row['invoice_id'])
                                            <button type="button" wire:click="viewPurchaseDetail({{ $row['invoice_id'] }})" class="font-black text-violet-600 underline-offset-4 hover:underline">
                                                {{ $row['invoice_no'] }}
                                            </button>
                                        @else
                                            <span class="font-semibold text-zinc-600">{{ $row['invoice_no'] }}</span>
                                        @endif
                                        <p class="mt-0.5 text-xs text-zinc-500">{{ $row['description'] }}</p>
                                        @if (! empty($row['cheque_no']) || ! empty($row['reference']))
                                            <p class="mt-0.5 text-xs font-semibold text-zinc-600">
                                                {{ __('Reference') }}: {{ $row['cheque_no'] ?: $row['reference'] }}
                                                @if (! empty($row['cheque_date']))
                                                    · {{ __('Cheque Date') }}: {{ $row['cheque_date']->format('Y-m-d') }}
                                                @endif
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-zinc-700">{{ strtoupper((string) ($row['payment_method'] ?? '-')) }}</td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-2 py-1 text-[10px] font-black uppercase tracking-wider',
                                            'bg-amber-50 text-amber-700' => ($row['cheque_status'] ?? $row['status']) === 'pending',
                                            'bg-emerald-50 text-emerald-700' => in_array(($row['cheque_status'] ?? $row['status']), ['passed', 'paid'], true),
                                            'bg-rose-50 text-rose-700' => ($row['cheque_status'] ?? $row['status']) === 'returned',
                                            'bg-zinc-100 text-zinc-600' => ! in_array(($row['cheque_status'] ?? $row['status']), ['pending', 'passed', 'paid', 'returned'], true),
                                        ])>{{ str((string) $row['status'])->replace('_', ' ')->headline() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-black text-rose-600">Rs {{ number_format((float) $row['credit'], 2) }}</td>
                                    <td class="px-4 py-3 text-right font-black text-emerald-600">Rs {{ number_format((float) $row['debit'], 2) }}</td>
                                    <td class="px-4 py-3 text-right font-black text-amber-600">
                                        @if (($row['pending_amount'] ?? null) !== null)
                                            Rs {{ number_format((float) $row['pending_amount'], 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-zinc-400">{{ __('No transactions match these filters.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 p-3 lg:hidden">
                    @forelse ($this->supplierTransactions as $row)
                        @php($invoiceTone = $this->transactionTone((string) $row['invoice_no'], $this->supplierTransactions, 'bg-white border-zinc-200'))
                        <div class="rounded-2xl border p-4 {{ $invoiceTone }}" wire:key="supplier-transaction-card-{{ $row['type'] }}-{{ $row['invoice_no'] }}-{{ $loop->index }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold text-zinc-500">{{ $row['date']?->format('Y-m-d') }}</p>
                                    <p class="mt-1 text-sm font-black text-zinc-900">{{ $row['label'] }}</p>
                                </div>
                                <span class="rounded-full bg-white/80 px-2 py-1 text-[10px] font-black uppercase tracking-wider text-zinc-600">{{ str((string) $row['status'])->headline() }}</span>
                            </div>
                            <p class="mt-3 text-xs font-semibold text-zinc-600">{{ $row['description'] }}</p>
                            @if (! empty($row['cheque_no']) || ! empty($row['reference']))
                                <p class="mt-1 text-xs font-semibold text-zinc-600">
                                    {{ __('Reference') }}: {{ $row['cheque_no'] ?: $row['reference'] }}
                                </p>
                            @endif
                            <div class="mt-3 flex items-center justify-between gap-3">
                                @if ($row['invoice_id'])
                                    <button type="button" wire:click="viewPurchaseDetail({{ $row['invoice_id'] }})" class="text-sm font-black text-violet-600">{{ $row['invoice_no'] }}</button>
                                @else
                                    <span class="text-sm font-bold text-zinc-700">{{ $row['invoice_no'] }}</span>
                                @endif
                                <div class="text-right">
                                    @if ((float) $row['credit'] > 0)
                                        <p class="text-sm font-black text-rose-600">Rs {{ number_format((float) $row['credit'], 2) }}</p>
                                    @else
                                        <p class="text-sm font-black text-emerald-600">Rs {{ number_format((float) $row['debit'], 2) }}</p>
                                    @endif
                                    @if (($row['pending_amount'] ?? null) !== null)
                                        <p class="mt-1 text-[11px] font-black uppercase tracking-wider text-amber-600">{{ __('Pending') }}: Rs {{ number_format((float) $row['pending_amount'], 2) }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="py-12 text-center text-sm text-zinc-400">{{ __('No transactions match these filters.') }}</div>
                    @endforelse
                </div>
            </div>
        </section>
    @endif

    <div x-cloak x-show="purchaseOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click.self="purchaseOpen = null; $wire.closePurchaseDetail()">
        <div class="max-h-[95vh] w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/60 p-5">
                <div>
                    <h3 class="font-display font-bold text-zinc-950">{{ __('Purchase Invoice') }} {{ $this->selectedPurchase?->invoice_no }}</h3>
                    <p class="mt-1 text-xs text-zinc-500">{{ $this->selectedPurchase?->supplier?->name }} · {{ $this->selectedPurchase?->date?->format('Y-m-d') }}</p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="closePurchaseDetail">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>
            <div class="max-h-[75vh] overflow-y-auto p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-zinc-100 p-4"><p class="text-[10px] font-black uppercase text-zinc-400">{{ __('Total') }}</p><p class="mt-1 text-lg font-black">Rs {{ number_format((float) ($this->selectedPurchase?->grand_total ?? 0), 2) }}</p></div>
                    <div class="rounded-2xl border border-zinc-100 p-4"><p class="text-[10px] font-black uppercase text-zinc-400">{{ __('Paid') }}</p><p class="mt-1 text-lg font-black text-emerald-600">Rs {{ number_format((float) ($this->selectedPurchase?->paid_amount ?? 0), 2) }}</p></div>
                    <div class="rounded-2xl border border-zinc-100 p-4"><p class="text-[10px] font-black uppercase text-zinc-400">{{ __('Due') }}</p><p class="mt-1 text-lg font-black text-rose-600">Rs {{ number_format((float) ($this->selectedPurchase?->due_amount ?? 0), 2) }}</p></div>
                </div>

                <div class="mt-4 rounded-2xl border border-zinc-100">
                    @forelse ($this->selectedPurchase?->items ?? [] as $item)
                        <div class="flex items-center justify-between gap-3 border-b border-zinc-100 p-4 last:border-0">
                            <div>
                                <p class="text-sm font-black text-zinc-900">{{ $item->product?->name ?? __('Product') }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('Qty') }} {{ $item->quantity }} · Rs {{ number_format((float) $item->cost_price, 2) }}</p>
                            </div>
                            <p class="text-sm font-black">Rs {{ number_format((float) $item->subtotal, 2) }}</p>
                        </div>
                    @empty
                        <div class="p-8 text-center text-sm text-zinc-400">{{ __('No invoice items found.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div x-cloak x-show="chequeOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm" @click.self="chequeOpen = null; $wire.closeChequeList()">
        <div class="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-3xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/60 p-5">
                <div>
                    <h3 class="font-display font-bold text-zinc-950">{{ __('Cheque Details') }}</h3>
                    <p class="mt-1 text-xs font-semibold text-zinc-500">
                        {{ $this->selectedSupplier?->name }}
                        @if ($selectedChequeStatus && $selectedChequeStatus !== 'all')
                            · {{ str($selectedChequeStatus)->headline() }}
                        @endif
                    </p>
                </div>
                <flux:button variant="ghost" size="sm" wire:click="closeChequeList">
                    <flux:icon.x-mark class="size-4" />
                </flux:button>
            </div>

            <div class="max-h-[72vh] overflow-y-auto p-4">
                <div class="grid gap-3">
                    @forelse ($this->selectedChequeRows as $cheque)
                        @php($invoiceTone = $this->invoiceTone((string) $cheque['invoice_no']))
                        <div class="rounded-2xl border p-4 {{ $invoiceTone }}" wire:key="supplier-cheque-{{ $cheque['cheque_no'] }}-{{ $loop->index }}">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span @class([
                                            'rounded-full px-2 py-1 text-[10px] font-black uppercase tracking-wider',
                                            'bg-amber-50 text-amber-700' => $cheque['status'] === 'pending',
                                            'bg-emerald-50 text-emerald-700' => $cheque['status'] === 'passed',
                                            'bg-rose-50 text-rose-700' => $cheque['status'] === 'returned',
                                            'bg-zinc-100 text-zinc-600' => ! in_array($cheque['status'], ['pending', 'passed', 'returned'], true),
                                        ])>{{ str((string) $cheque['status'])->headline() }}</span>
                                        <span class="text-xs font-semibold text-zinc-500">{{ $cheque['date']?->format('Y-m-d') }}</span>
                                    </div>
                                    <p class="mt-2 text-sm font-black text-zinc-950">{{ __('Cheque No') }}: {{ $cheque['cheque_no'] }}</p>
                                    <p class="mt-1 text-sm font-bold text-violet-700">{{ __('Invoice No') }}: {{ $cheque['invoice_no'] }}</p>
                                    @if (! empty($cheque['cheque_bank']) || ! empty($cheque['cheque_date']))
                                        <p class="mt-1 text-xs font-semibold text-zinc-600">
                                            @if (! empty($cheque['cheque_bank']))
                                                {{ __('Bank') }}: {{ $cheque['cheque_bank'] }}
                                            @endif
                                            @if (! empty($cheque['cheque_date']))
                                                @if (! empty($cheque['cheque_bank'])) · @endif
                                                {{ __('Cheque Date') }}: {{ $cheque['cheque_date']->format('Y-m-d') }}
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <div class="shrink-0 text-left sm:text-right">
                                    <p class="text-lg font-black text-zinc-950">Rs {{ number_format((float) $cheque['amount'], 2) }}</p>
                                    @if ($cheque['invoice_id'])
                                        <button type="button" wire:click="viewPurchaseDetail({{ $cheque['invoice_id'] }})" class="mt-2 text-xs font-black text-violet-600 underline-offset-4 hover:underline">
                                            {{ __('Open Invoice') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="py-12 text-center text-sm text-zinc-400">{{ __('No cheques found for this supplier.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
