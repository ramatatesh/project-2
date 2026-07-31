<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_plan_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('domain')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_plan_usages');
    }
};
