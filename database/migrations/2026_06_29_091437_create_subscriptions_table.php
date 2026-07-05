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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->decimal('monthly_price', 10)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
        DB::statement("ALTER TYPE plan_type_enum ADD VALUE IF NOT EXISTS 'paid'");
        DB::statement("ALTER TYPE plan_type_enum ADD VALUE IF NOT EXISTS 'free'");
        DB::statement("alter table \"subscriptions\" add column \"plan_type\" plan_type_enum not null");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
