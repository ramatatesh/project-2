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
        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('attendance_record_id');
            $table->uuid('adjusted_by');
            $table->timestamp('old_check_in')->nullable();
            $table->timestamp('new_check_in')->nullable();
            $table->timestamp('old_check_out')->nullable();
            $table->timestamp('new_check_out')->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');
    }
};
