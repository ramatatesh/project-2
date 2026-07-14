<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ترحيل أسماء الأدوار القديمة (company_admin / admin) إلى الاسم الموحد general_manager
     * المتوافق مع App\Enums\Role، وتحديث القيمة الافتراضية لعمود role.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'company_admin')->update(['role' => 'general_manager']);
        DB::table('users')->where('role', 'admin')->update(['role' => 'general_manager']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('general_manager')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('company_admin')->change();
        });

        DB::table('users')->where('role', 'general_manager')->update(['role' => 'company_admin']);
    }
};
