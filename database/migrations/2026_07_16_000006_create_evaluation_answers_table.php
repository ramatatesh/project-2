<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_review_id');
            $table->uuid('evaluation_template_question_id');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedTinyInteger('hr_score')->nullable();
            $table->timestamp('created_at')->nullable()->default(DB::raw('now()'));
            $table->timestamp('updated_at')->nullable();

            $table->foreign('evaluation_review_id')->references('id')->on('evaluation_reviews')->onDelete('cascade');
            $table->foreign('evaluation_template_question_id')->references('id')->on('evaluation_template_questions')->onDelete('cascade');
            $table->unique(['evaluation_review_id', 'evaluation_template_question_id'], 'unique_evaluation_answers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_answers');
    }
};
