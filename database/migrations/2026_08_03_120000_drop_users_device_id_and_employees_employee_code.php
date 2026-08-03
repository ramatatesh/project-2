<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'device_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('device_id');
            });
        }

        if (Schema::hasColumn('employees', 'employee_code')) {
            try {
                Schema::table('employees', function (Blueprint $table) {
                    $table->dropUnique('employees_employee_code_key');
                });
            } catch (\Throwable) {
                try {
                    Schema::table('employees', function (Blueprint $table) {
                        $table->dropUnique(['employee_code']);
                    });
                } catch (\Throwable) {
                    // Unique index may already be absent on some drivers.
                }
            }

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('employee_code');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'device_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('device_id')->nullable();
            });
        }

        if (! Schema::hasColumn('employees', 'employee_code')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('employee_code', 100)->nullable()->unique('employees_employee_code_key');
            });
        }
    }
};
