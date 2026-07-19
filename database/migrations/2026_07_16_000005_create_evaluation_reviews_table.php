<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('evaluation_cycle_id');
            $table->uuid('employee_id');
            $table->uuid('reviewer_id');
            $table->string('review_type', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('total_score', 5, 2)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('evaluation_cycle_id')->references('id')->on('evaluation_cycles')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['evaluation_cycle_id', 'employee_id', 'reviewer_id', 'review_type'], 'unique_evaluation_reviews');
            $table->index(['evaluation_cycle_id', 'employee_id']);
            $table->index('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_reviews');
    }
};
