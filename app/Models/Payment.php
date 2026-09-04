<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Customer;
use App\Models\Sale;

#[Fillable(['paymentable_type', 'paymentable_id', 'amount', 'payment_method', 'date', 'reference', 'cheque_bank', 'cheque_no', 'cheque_date', 'cheque_status', 'cheque_type', 'cheque_processed_at', 'source_payment_id', 'party_customer_id', 'notes'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'cheque_date' => 'date',
            'cheque_processed_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function pendingCheque(Builder $query): void
    {
        $query->where('payment_method', 'cheque')
            ->where('cheque_status', 'pending');
    }

    public function paymentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function issuedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'source_payment_id');
    }

    public function partyCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'party_customer_id');
    }

    public function resolveCustomerName(): string
    {
        if ($this->partyCustomer) {
            return $this->partyCustomer->name;
        }
        if ($this->sourcePayment) {
            return $this->sourcePayment->resolveCustomerName();
        }
        if ($this->paymentable instanceof Sale) {
            return $this->paymentable->customer?->name ?? __('Unknown Customer');
        }
        if ($this->paymentable instanceof Customer) {
            return $this->paymentable->name ?? __('Unknown Customer');
        }
        return __('Unknown Customer');
    }
}
