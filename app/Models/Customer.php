<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'email', 'address', 'opening_balance', 'due_balance'])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'due_balance' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function recalculateDueBalance(): float
    {
        $salesDue = (float) $this->sales()->sum('due_amount');
        $validPayments = $this->payments()
            ->where(function ($query) {
                $query->where('payment_method', '!=', 'cheque')
                    ->orWhere(function ($q) {
                        $q->where('payment_method', 'cheque')
                            ->where('cheque_status', '!=', 'returned');
                    });
            })
            ->get();

        $accountCredits = (float) $validPayments->where('payment_method', '!=', 'adjustment_debit')->sum('amount');
        $accountDebits = (float) $validPayments->where('payment_method', 'adjustment_debit')->sum('amount');

        $due = round(max(0, (float) $this->opening_balance + $salesDue + $accountDebits - $accountCredits), 2);
        $this->update(['due_balance' => $due]);

        return $due;
    }
}
