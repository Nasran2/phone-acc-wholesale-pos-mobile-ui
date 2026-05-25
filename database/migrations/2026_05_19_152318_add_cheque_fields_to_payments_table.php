<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('cheque_bank')->nullable()->after('reference');
            $table->string('cheque_no')->nullable()->after('cheque_bank');
            $table->date('cheque_date')->nullable()->after('cheque_no');
            $table->string('cheque_status')->nullable()->after('cheque_date')->index(); // pending, passed, returned
            $table->timestamp('cheque_processed_at')->nullable()->after('cheque_status');

            $table->index(['payment_method', 'cheque_status', 'cheque_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_method', 'cheque_status', 'cheque_date']);
            $table->dropIndex(['cheque_status']);
            $table->dropColumn([
                'cheque_bank',
                'cheque_no',
                'cheque_date',
                'cheque_status',
                'cheque_processed_at',
            ]);
        });
    }
};
