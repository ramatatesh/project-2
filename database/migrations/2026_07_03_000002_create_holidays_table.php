<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->string('holiday_type', 50);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('repeats_annually')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
