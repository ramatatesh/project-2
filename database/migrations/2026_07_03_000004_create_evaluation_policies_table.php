<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->boolean('apply_review_to_salary')->default(false);
            $table->decimal('excellent_bonus_percent', 5, 2)->nullable();
            $table->decimal('good_bonus_percent', 5, 2)->nullable();
            $table->decimal('poor_deduction_percent', 5, 2)->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_policies');
    }
};
