<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('reason');
        });

        Schema::table('salary_advance_installments', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });

        Schema::table('salary_advance_installments', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
