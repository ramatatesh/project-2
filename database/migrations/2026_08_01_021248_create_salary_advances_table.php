<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->decimal('requested_amount', 12, 2);
            $table->unsignedInteger('repayment_months');
            $table->decimal('monthly_installment', 12, 2);
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('pending_department_manager');
            $table->uuid('approved_by_manager_id')->nullable();
            $table->uuid('approved_by_hr_id')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
    }
};
