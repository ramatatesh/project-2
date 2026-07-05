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
        Schema::table('salary_records', function (Blueprint $table) {
            $table->foreign(['closed_by'], 'salary_records_closed_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['company_id'], 'salary_records_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['employee_id'], 'salary_records_employee_id_fkey')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_records', function (Blueprint $table) {
            $table->dropForeign('salary_records_closed_by_fkey');
            $table->dropForeign('salary_records_company_id_fkey');
            $table->dropForeign('salary_records_employee_id_fkey');
        });
    }
};
