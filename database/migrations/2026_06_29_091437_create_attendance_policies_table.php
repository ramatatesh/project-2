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
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->integer('monthly_late_threshold_min')->nullable()->default(60);
            $table->integer('consecutive_absent_alert')->nullable()->default(3);
            $table->boolean('enable_gps_verification')->nullable()->default(true);
            $table->decimal('company_latitude', 10, 8)->nullable();
            $table->decimal('company_longitude', 11, 8)->nullable();
            $table->integer('allowed_radius')->nullable();
            $table->integer('allowed_perimeter')->default(150); // القيمة الافتراضية للتصميم 150 متر
             $table->decimal('latitude', 10, 8)->nullable();
             $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
