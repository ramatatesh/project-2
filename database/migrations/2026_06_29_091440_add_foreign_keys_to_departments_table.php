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
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign(['company_id'], 'departments_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['manager_id'], 'fk_departments_manager')->references(['id'])->on('employees')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign('departments_company_id_fkey');
            $table->dropForeign('fk_departments_manager');
        });
    }
};
