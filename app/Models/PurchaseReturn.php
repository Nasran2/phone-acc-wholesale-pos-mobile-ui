<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['supplier_id', 'purchase_id', 'replacement_purchase_id', 'operation_token', 'invoice_no', 'date', 'return_type', 'total_amount', 'refund_amount', 'adjusted_amount', 'due_allocations', 'notes'])]
class PurchaseReturn extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'refund_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'due_allocations' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function replacementPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'replacement_purchase_id');
    }

    public static function dueReductionFor(int $purchaseId): float
    {
        return (float) static::query()->whereNotNull('due_allocations')->get(['due_allocations'])
            ->sum(fn (self $return): float => (float) ($return->due_allocations[$purchaseId] ?? 0));
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }
}
