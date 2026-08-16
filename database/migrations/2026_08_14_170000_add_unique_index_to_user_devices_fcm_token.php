<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique('fcm_token', 'user_devices_fcm_token_unique');
            $table->index(['user_id', 'is_active'], 'user_devices_user_id_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropUnique('user_devices_fcm_token_unique');
            $table->dropIndex('user_devices_user_id_is_active_index');
        });
    }
};
