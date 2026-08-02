<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->string('duration_type', 20)->default('hour')->after('request_date');
            $table->decimal('calculated_amount', 12, 2)->nullable()->after('hours_approved');
            $table->text('rejection_reason')->nullable()->after('review_notes');
        });

        // Align status vocabulary with leave/advance dual-approval workflow.
        DB::table('overtime_requests')
            ->where('status', 'pending')
            ->update(['status' => 'pending_department_manager']);

        DB::statement("ALTER TABLE overtime_requests ALTER COLUMN status SET DEFAULT 'pending_department_manager'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE overtime_requests ALTER COLUMN status SET DEFAULT 'pending'");

        DB::table('overtime_requests')
            ->where('status', 'pending_department_manager')
            ->update(['status' => 'pending']);

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn(['duration_type', 'calculated_amount', 'rejection_reason']);
        });
    }
};
