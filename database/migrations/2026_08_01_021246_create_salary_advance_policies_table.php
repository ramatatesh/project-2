<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->decimal('max_advance_percentage', 5, 2);
            $table->unsignedInteger('max_repayment_months');
            $table->boolean('allow_multiple_active_advances')->default(false);
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_policies');
    }
};
