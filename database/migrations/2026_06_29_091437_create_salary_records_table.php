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
        Schema::create('salary_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->integer('month');
            $table->integer('year');
            $table->decimal('base_salary', 10);
            $table->decimal('overtime_amount', 10)->nullable()->default(0);
            $table->decimal('bonus_amount', 10)->nullable()->default(0);
            $table->decimal('late_deduction', 10)->nullable()->default(0);
            $table->decimal('absent_deduction', 10)->nullable()->default(0);
            $table->decimal('loan_deduction', 10)->nullable()->default(0);
            $table->decimal('manual_bonus', 10)->nullable()->default(0);
            $table->decimal('manual_deduction', 10)->nullable()->default(0);
            $table->decimal('net_salary', 10);
            $table->string('status', 50)->nullable()->default('draft');
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->unique(['company_id', 'employee_id', 'month', 'year'], 'unique_salary_employee_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_records');
    }
};
