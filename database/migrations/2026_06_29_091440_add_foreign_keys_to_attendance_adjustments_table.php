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
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->foreign(['adjusted_by'], 'attendance_adjustments_adjusted_by_fkey')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['attendance_record_id'], 'attendance_adjustments_attendance_record_id_fkey')->references(['id'])->on('attendance_records')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['company_id'], 'attendance_adjustments_company_id_fkey')->references(['id'])->on('companies')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->dropForeign('attendance_adjustments_adjusted_by_fkey');
            $table->dropForeign('attendance_adjustments_attendance_record_id_fkey');
            $table->dropForeign('attendance_adjustments_company_id_fkey');
        });
    }
};
