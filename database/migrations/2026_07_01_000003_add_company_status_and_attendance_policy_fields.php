<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->after('domain');
        });

        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->string('work_start_time')->nullable()->after('allowed_radius');
            $table->string('work_end_time')->nullable()->after('work_start_time');
            $table->integer('allowed_late_minutes')->nullable()->default(15)->after('work_end_time');
            $table->integer('allowed_early_leave_minutes')->nullable()->default(15)->after('allowed_late_minutes');
            $table->json('work_days')->nullable()->after('allowed_early_leave_minutes');
            $table->integer('minimum_daily_hours')->nullable()->default(8)->after('work_days');
            $table->boolean('allows_overtime')->nullable()->default(true)->after('minimum_daily_hours');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['work_start_time', 'work_end_time', 'allowed_late_minutes', 'allowed_early_leave_minutes', 'work_days', 'minimum_daily_hours', 'allows_overtime']);
        });
    }
};
