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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreign(['company_id'], 'leave_requests_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['employee_id'], 'leave_requests_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['leave_type_id'], 'leave_requests_leave_type_id_fkey')->references(['id'])->on('leave_types')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['reviewed_by'], 'leave_requests_reviewed_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign('leave_requests_company_id_fkey');
            $table->dropForeign('leave_requests_employee_id_fkey');
            $table->dropForeign('leave_requests_leave_type_id_fkey');
            $table->dropForeign('leave_requests_reviewed_by_fkey');
        });
    }
};
