<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->enum('plan_type', ['free', 'paid']);
            $table->decimal('monthly_price', 10)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
