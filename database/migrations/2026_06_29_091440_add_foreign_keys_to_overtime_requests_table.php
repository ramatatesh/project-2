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
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreign(['company_id'], 'overtime_requests_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['dept_manager_approval'], 'overtime_requests_dept_manager_approval_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['employee_id'], 'overtime_requests_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['hr_registered_by'], 'overtime_requests_hr_registered_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropForeign('overtime_requests_company_id_fkey');
            $table->dropForeign('overtime_requests_dept_manager_approval_fkey');
            $table->dropForeign('overtime_requests_employee_id_fkey');
            $table->dropForeign('overtime_requests_hr_registered_by_fkey');
        });
    }
};
