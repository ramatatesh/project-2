<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MarkAbsentEmployees extends Command
{
    /**
     * Defaults to yesterday (not today) because the command runs shortly after
     * midnight - see the scheduling comment in bootstrap/app.php for why.
     */
    protected $signature = 'attendance:mark-absent {--date= : Date to process (Y-m-d). Defaults to yesterday.}';

    protected $description = 'Mark employees absent for a given date if they have no attendance record and no approved leave, weekly holiday, or company holiday covering it.';

    public function handle(AttendanceService $attendanceService): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();

        $result = $attendanceService->markAbsentForDate($date);

        $this->info("Marked {$result['created']} employee(s) absent for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
