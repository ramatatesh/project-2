<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advance_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('salary_advance_id');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 50)->default('pending');
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));

            $table->foreign('salary_advance_id')->references('id')->on('salary_advances')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_installments');
    }
};
