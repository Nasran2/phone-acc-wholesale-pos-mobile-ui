<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['sale_id', 'customer_id', 'invoice_no', 'date', 'return_type', 'refund_amount', 'adjusted_amount', 'notes', 'due_allocations', 'checkout_sale_id'])]
class SaleReturn extends Model
{
    protected $table = 'returns';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'refund_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'due_allocations' => 'array',
            'checkout_sale_id' => 'integer',
        ];
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->items->sum('subtotal'), 2));
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->return_type) {
            'exchange' => __('Same product exchange'),
            'adjust_due' => __('Customer due reduction'),
            'cash_refund' => __('Cash refund'),
            'bill_credit' => __('Credit on another bill'),
            default => str($this->return_type)->replace('_', ' ')->headline()->toString(),
        });
    }

    public function faultyItems(): HasMany
    {
        return $this->hasMany(FaultyItem::class, 'source_sale_return_id');
    }

    public function hasSupplierReturns(): bool
    {
        return $this->faultyItems->contains('status', 'returned')
            || PurchaseReturnItem::query()->whereIn('faulty_item_id', $this->faultyItems->pluck('id'))->exists();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'return_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }
}
