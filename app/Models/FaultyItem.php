<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'quantity', 'source_sale_return_id', 'status'])]
class FaultyItem extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceSaleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'source_sale_return_id');
    }
}
