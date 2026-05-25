<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sale_id', 'customer_id', 'invoice_no', 'date', 'return_type', 'refund_amount', 'adjusted_amount', 'notes'])]
class SaleReturn extends Model
{
    protected $table = 'returns';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'refund_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
        ];
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
}
