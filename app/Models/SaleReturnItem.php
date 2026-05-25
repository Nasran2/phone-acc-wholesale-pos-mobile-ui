<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['return_id', 'product_id', 'quantity', 'refund_price', 'subtotal'])]
class SaleReturnItem extends Model
{
    protected $table = 'return_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'refund_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function returnLog(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
