<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_return_id', 'purchase_item_id', 'product_id', 'faulty_item_id', 'quantity', 'refund_price', 'subtotal'])]
class PurchaseReturnItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'refund_price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function faultyItem(): BelongsTo
    {
        return $this->belongsTo(FaultyItem::class);
    }
}
