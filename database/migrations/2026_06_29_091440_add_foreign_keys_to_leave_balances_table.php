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
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->foreign(['company_id'], 'leave_balances_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['employee_id'], 'leave_balances_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['leave_type_id'], 'leave_balances_leave_type_id_fkey')->references(['id'])->on('leave_types')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropForeign('leave_balances_company_id_fkey');
            $table->dropForeign('leave_balances_employee_id_fkey');
            $table->dropForeign('leave_balances_leave_type_id_fkey');
        });
    }
};
