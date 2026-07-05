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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->integer('year');
            $table->decimal('total_days', 5);
            $table->decimal('used_days', 5)->nullable()->default(0);
            $table->decimal('remaining_days', 5)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->unique(['employee_id', 'leave_type_id', 'year'], 'unique_employee_leave_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
