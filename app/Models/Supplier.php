<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'email', 'address', 'company_name', 'opening_balance', 'due_balance'])]
class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'due_balance' => 'decimal:2',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function purchasePayments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Purchase::class, 'supplier_id', 'paymentable_id')
            ->where('paymentable_type', (new Purchase)->getMorphClass());
    }

    #[Scope]
    protected function withPendingChequeHold(Builder $query): void
    {
        $query->withSum([
            'payments as pending_account_cheque_hold' => fn (Builder $payments) => $payments->pendingCheque(),
            'purchasePayments as pending_purchase_cheque_hold' => fn (Builder $payments) => $payments->pendingCheque(),
        ], 'amount');
    }

    protected function pendingChequeHoldAmount(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): float => round(
                (float) ($attributes['pending_account_cheque_hold'] ?? 0) + (float) ($attributes['pending_purchase_cheque_hold'] ?? 0), 2
            ),
        );
    }
}
