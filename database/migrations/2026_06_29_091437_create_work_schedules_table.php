<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->time('work_start');
            $table->time('work_end');
            $table->integer('grace_minutes')->nullable()->default(0);
            $table->integer('early_leave_minutes')->nullable()->default(0);
            $table->integer('min_hours_per_day')->nullable();
            $table->boolean('overtime_allowed')->nullable()->default(true);
            $table->boolean('is_default')->nullable()->default(false);
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
