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
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->foreign(['company_id'], 'salary_adjustments_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['created_by'], 'salary_adjustments_created_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['salary_record_id'], 'salary_adjustments_salary_record_id_fkey')->references(['id'])->on('salary_records')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropForeign('salary_adjustments_company_id_fkey');
            $table->dropForeign('salary_adjustments_created_by_fkey');
            $table->dropForeign('salary_adjustments_salary_record_id_fkey');
        });
    }
};
