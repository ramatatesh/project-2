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
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->date('request_date');
            $table->integer('hours_requested');
            $table->text('reason')->nullable();
            $table->string('status', 50)->nullable()->default('pending');
            $table->text('review_notes')->nullable();
            $table->uuid('dept_manager_approval')->nullable();
            $table->timestamp('dept_approved_at')->nullable();
            $table->integer('hours_approved')->nullable();
            $table->uuid('hr_registered_by')->nullable();
            $table->timestamp('hr_registered_at')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
