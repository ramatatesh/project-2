<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assistant_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('employee_id');
            $table->uuid('company_id');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            $table->index(['user_id', 'company_id']);
            $table->index(['employee_id', 'company_id']);
            $table->index('updated_at');
        });

        Schema::create('employee_assistant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('employee_assistant_session_id');
            $table->string('role', 20); // user | assistant
            $table->text('message');
            $table->timestamps();

            $table->foreign('employee_assistant_session_id', 'ea_messages_session_fk')
                ->references('id')
                ->on('employee_assistant_sessions')
                ->onDelete('cascade');

            $table->index(['employee_assistant_session_id', 'created_at'], 'ea_messages_session_created_idx');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assistant_messages');
        Schema::dropIfExists('employee_assistant_sessions');
    }
};
