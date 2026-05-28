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
            $table->string('cheque_type')->nullable()->after('cheque_status')->index();
            $table->foreignId('source_payment_id')->nullable()->after('cheque_processed_at')->constrained('payments')->nullOnDelete();
            $table->foreignId('party_customer_id')->nullable()->after('source_payment_id')->constrained('customers')->nullOnDelete();

            $table->index(['source_payment_id', 'cheque_status']);
            $table->index(['party_customer_id', 'cheque_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['party_customer_id', 'cheque_status']);
            $table->dropIndex(['source_payment_id', 'cheque_status']);
            $table->dropConstrainedForeignId('party_customer_id');
            $table->dropConstrainedForeignId('source_payment_id');
            $table->dropIndex(['cheque_type']);
            $table->dropColumn('cheque_type');
        });
    }
};
