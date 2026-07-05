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
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->uuid('reviewer_id');
            $table->string('review_period', 100);
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('score')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('recommend_for_promotion')->nullable();
            $table->string('status', 50)->nullable()->default('draft');
            $table->uuid('hr_reviewed_by')->nullable();
            $table->timestamp('hr_reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->unique(['employee_id', 'period_start', 'period_end'], 'unique_employee_review_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
