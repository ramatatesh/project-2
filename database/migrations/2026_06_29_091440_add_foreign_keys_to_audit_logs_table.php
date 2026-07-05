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
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign(['actor_id'], 'audit_logs_actor_id_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['company_id'], 'audit_logs_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign('audit_logs_actor_id_fkey');
            $table->dropForeign('audit_logs_company_id_fkey');
        });
    }
};
