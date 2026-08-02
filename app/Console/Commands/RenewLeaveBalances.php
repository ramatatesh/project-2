<?php

namespace App\Console\Commands;

use App\Services\LeaveBalanceService;
use Illuminate\Console\Command;

class RenewLeaveBalances extends Command
{
    protected $signature = 'leaves:renew-balances {--year= : Target year (defaults to current calendar year)}';

    protected $description = 'Create yearly leave balance rows for all active employees and active leave types (annual renewal).';

    public function handle(LeaveBalanceService $leaveBalanceService): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $result = $leaveBalanceService->renewForYear($year);

        $this->info("Leave balances for {$year}: created {$result['created']}, skipped existing {$result['skipped']}.");

        return self::SUCCESS;
    }
}
