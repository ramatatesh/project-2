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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('full_name');
            $table->string('father_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('email')->unique('users_email_key');
            $table->string('password_hash');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('status', 50)->nullable()->default('active');
            $table->string('device_id')->nullable();
            $table->boolean('is_first_login')->nullable()->default(true);
            $table->timestamp('last_login')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('role', 50)->default('general_manager');
            $table->boolean('two_factor_enabled')->default(false)->after('role');
            $table->timestamp('created_at')->nullable()->default(DB::raw("now()"));
        });
       // DB::statement("alter table \"users\" add column \"role\" role_enum not null");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
