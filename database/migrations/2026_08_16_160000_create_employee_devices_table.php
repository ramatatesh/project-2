<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('employee_id');
            $table->string('device_id');
            $table->timestamp('bound_at');
            $table->boolean('is_active')->default(true);
            $table->timestamp('unbound_at')->nullable();
            $table->uuid('unbound_by')->nullable();
            $table->string('unbind_reason', 2000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('unbound_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['company_id', 'device_id']);
            $table->index(['employee_id', 'is_active']);
        });

        // One active binding per employee; one active binding per device within a company.
        DB::statement('CREATE UNIQUE INDEX employee_devices_active_employee_uidx ON employee_devices (employee_id) WHERE is_active = true');
        DB::statement('CREATE UNIQUE INDEX employee_devices_active_device_uidx ON employee_devices (company_id, device_id) WHERE is_active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_devices');
    }
};
