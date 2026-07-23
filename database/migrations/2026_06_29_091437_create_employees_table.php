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
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique('employees_user_id_key');
            $table->uuid('company_id');
            $table->uuid('department_id')->nullable();
            $table->string('employee_code', 100)->nullable()->unique('employees_employee_code_key');
            $table->string('education')->nullable();
            $table->string('job_title');
            $table->decimal('base_salary', 10);
            $table->date('hire_date');
            $table->string('employment_type', 100)->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
