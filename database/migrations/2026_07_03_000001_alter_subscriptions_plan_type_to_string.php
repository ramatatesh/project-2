<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change plan_type enum to varchar to avoid enum mismatches across environments
        DB::statement("ALTER TABLE subscriptions ALTER COLUMN plan_type TYPE varchar(50) USING plan_type::text");
    }

    public function down(): void
    {
        // Attempt to convert back to enum if exists; best-effort rollback
        DB::statement("ALTER TABLE subscriptions ALTER COLUMN plan_type TYPE plan_type_enum USING plan_type::plan_type_enum");
    }
};
