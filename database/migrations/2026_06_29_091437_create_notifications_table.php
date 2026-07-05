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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('type', 100);
            $table->string('title');
            $table->text('body')->nullable();
            $table->uuid('related_id')->nullable();
            $table->string('related_table', 100)->nullable();
            $table->boolean('is_read')->nullable()->default(false);
            $table->string('delivery_channel', 100);
            $table->boolean('push_sent')->nullable()->default(false);
            $table->timestamp('push_sent_at')->nullable()->default(DB::raw("now()"));
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
