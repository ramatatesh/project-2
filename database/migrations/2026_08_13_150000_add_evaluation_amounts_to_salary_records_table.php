<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_records', function (Blueprint $table) {
            $table->decimal('evaluation_bonus_amount', 10)->nullable()->default(0)->after('manual_deduction');
            $table->decimal('evaluation_deduction_amount', 10)->nullable()->default(0)->after('evaluation_bonus_amount');
        });
    }

    public function down(): void
    {
        Schema::table('salary_records', function (Blueprint $table) {
            $table->dropColumn(['evaluation_bonus_amount', 'evaluation_deduction_amount']);
        });
    }
};
