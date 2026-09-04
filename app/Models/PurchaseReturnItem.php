<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_return_id', 'product_id', 'faulty_item_id', 'quantity', 'refund_price', 'subtotal'])]
class PurchaseReturnItem extends Model
{
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
