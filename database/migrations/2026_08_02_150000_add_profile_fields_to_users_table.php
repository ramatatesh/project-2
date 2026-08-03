<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('phone');
            $table->string('marital_status', 30)->nullable()->after('gender');
            $table->string('nationality', 100)->nullable()->after('marital_status');
            $table->string('residence')->nullable()->after('nationality');
            $table->boolean('profile_completed')->default(false)->after('is_first_login');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gender', 'marital_status', 'nationality', 'residence', 'profile_completed']);
        });
    }
};
