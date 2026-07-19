<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_policies', function (Blueprint $table) {
            $table->decimal('manager_weight', 5, 2)->nullable()->after('apply_review_to_salary');
            $table->decimal('self_weight', 5, 2)->nullable()->after('manager_weight');
            $table->decimal('peer_weight', 5, 2)->nullable()->after('self_weight');
            $table->unsignedTinyInteger('peer_reviews_count')->default(0)->after('peer_weight');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_policies', function (Blueprint $table) {
            $table->dropColumn(['manager_weight', 'self_weight', 'peer_weight', 'peer_reviews_count']);
        });
    }
};
