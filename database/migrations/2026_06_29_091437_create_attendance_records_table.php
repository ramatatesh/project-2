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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->date('work_date');
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->decimal('check_in_lat', 10, 8)->nullable();
            $table->decimal('check_in_lng', 11, 8)->nullable();
            $table->decimal('check_out_lat', 10, 8)->nullable();
            $table->decimal('check_out_lng', 11, 8)->nullable();
            $table->string('check_in_device_id')->nullable();
            $table->string('qr_token_used')->nullable();
            $table->integer('late_minutes')->nullable()->default(0);
            $table->integer('early_leave_minutes')->nullable()->default(0);
            $table->integer('total_work_minutes')->nullable();
            $table->string('status', 50);
            $table->string('attendance_type', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->unique(['company_id', 'employee_id', 'work_date'], 'unique_company_employee_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
