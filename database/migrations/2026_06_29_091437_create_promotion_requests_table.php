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
        Schema::create('promotion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('proposed_by');
            $table->string('current_job_title');
            $table->string('proposed_job_title');
            $table->decimal('current_salary', 10);
            $table->decimal('proposed_salary', 10);
            $table->text('justification')->nullable();
            $table->string('status', 50)->nullable()->default('pending');
            $table->uuid('hr_reviewed_by')->nullable();
            $table->uuid('gm_approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_requests');
    }
};
