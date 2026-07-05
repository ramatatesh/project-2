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
        Schema::table('promotion_requests', function (Blueprint $table) {
            $table->foreign(['company_id'], 'promotion_requests_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['employee_id'], 'promotion_requests_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['gm_approved_by'], 'promotion_requests_gm_approved_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['hr_reviewed_by'], 'promotion_requests_hr_reviewed_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['proposed_by'], 'promotion_requests_proposed_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotion_requests', function (Blueprint $table) {
            $table->dropForeign('promotion_requests_company_id_fkey');
            $table->dropForeign('promotion_requests_employee_id_fkey');
            $table->dropForeign('promotion_requests_gm_approved_by_fkey');
            $table->dropForeign('promotion_requests_hr_reviewed_by_fkey');
            $table->dropForeign('promotion_requests_proposed_by_fkey');
        });
    }
};
