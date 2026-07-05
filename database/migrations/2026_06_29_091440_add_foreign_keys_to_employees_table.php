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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign(['company_id'], 'employees_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['department_id'], 'employees_department_id_fkey')->references(['id'])->on('departments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'], 'employees_user_id_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_company_id_fkey');
            $table->dropForeign('employees_department_id_fkey');
            $table->dropForeign('employees_user_id_fkey');
        });
    }
};
