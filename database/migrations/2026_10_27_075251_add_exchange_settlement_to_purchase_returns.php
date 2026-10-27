<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->decimal('return_credit_amount', 12, 2)->default(0);
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('replacement_purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->uuid('operation_token')->nullable()->unique();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->json('due_allocations')->nullable();
        });
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_item_id');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_purchase_id');
            $table->dropColumn(['operation_token', 'total_amount', 'due_allocations']);
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('return_credit_amount');
        });
    }
};
