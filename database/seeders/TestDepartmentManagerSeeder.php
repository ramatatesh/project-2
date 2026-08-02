<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Alias seeder: creates a full company team (HR + Department Manager + Employee)
 * all belonging to the same company — preferred for leave/approval workflow testing.
 */
class TestDepartmentManagerSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TestCompanyTeamSeeder::class);
    }
}
