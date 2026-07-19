<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_template_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_template_id');
            $table->text('question');
            $table->string('response_type', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('weight', 5, 2)->default(1);
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));

            $table->foreign('evaluation_template_id')->references('id')->on('evaluation_templates')->onDelete('cascade');
            $table->index('evaluation_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_template_questions');
    }
};
