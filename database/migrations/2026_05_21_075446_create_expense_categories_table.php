<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('expense_categories')->insert([
            ['name' => 'Utility Bills', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Rent', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Salary', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Internet & Phone', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transport & Fuel', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Marketing', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Repairs & Maintenance', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tea & Meals', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other Overhead', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
