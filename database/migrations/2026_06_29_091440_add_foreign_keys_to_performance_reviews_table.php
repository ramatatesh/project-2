<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->foreign(['company_id'], 'performance_reviews_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['employee_id'], 'performance_reviews_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['hr_reviewed_by'], 'performance_reviews_hr_reviewed_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reviewer_id'], 'performance_reviews_reviewer_id_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropForeign('performance_reviews_company_id_fkey');
            $table->dropForeign('performance_reviews_employee_id_fkey');
            $table->dropForeign('performance_reviews_hr_reviewed_by_fkey');
            $table->dropForeign('performance_reviews_reviewer_id_fkey');
        });
    }
};
