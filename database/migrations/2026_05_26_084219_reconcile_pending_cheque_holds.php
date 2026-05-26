<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('sales')
            ->where('payment_status', 'cheque_pending')
            ->orderBy('id')
            ->chunkById(100, function ($sales): void {
                foreach ($sales as $sale) {
                    $pendingChequeAmount = (float) DB::table('payments')
                        ->where('paymentable_type', 'App\\Models\\Sale')
                        ->where('paymentable_id', $sale->id)
                        ->where('payment_method', 'cheque')
                        ->where('cheque_status', 'pending')
                        ->sum('amount');

                    if ($pendingChequeAmount <= 0) {
                        continue;
                    }

                    $oldDueAmount = (float) $sale->due_amount;
                    $newDueAmount = round(max(0, (float) $sale->grand_total - (float) $sale->paid_amount - $pendingChequeAmount), 2);

                    if (round($oldDueAmount, 2) === $newDueAmount) {
                        continue;
                    }

                    DB::table('sales')
                        ->where('id', $sale->id)
                        ->update([
                            'due_amount' => $newDueAmount,
                            'updated_at' => now(),
                        ]);

                    $dueDelta = round($newDueAmount - $oldDueAmount, 2);

                    if ($sale->customer_id && $dueDelta !== 0.0) {
                        $customer = DB::table('customers')->where('id', $sale->customer_id)->first();

                        if ($customer) {
                            DB::table('customers')
                                ->where('id', $customer->id)
                                ->update([
                                    'due_balance' => round(max(0, (float) $customer->due_balance + $dueDelta), 2),
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
