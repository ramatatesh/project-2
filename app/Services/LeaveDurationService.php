<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\HolidayPolicy;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class LeaveDurationService
{
    /**
     * Count working days between two dates (inclusive), excluding weekly holidays
     * and official company holidays.
     */
    public function calculateWorkingDays(string $companyId, Carbon|string $startDate, Carbon|string $endDate): int
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $weeklyHolidays = $this->weeklyHolidaysFor($companyId);
        $holidays = $this->holidaysFor($companyId);

        $workingDays = 0;

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if ($this->isNonWorkingDay($date, $weeklyHolidays, $holidays)) {
                continue;
            }

            $workingDays++;
        }

        return $workingDays;
    }

    /**
     * @param  Collection<int, Holiday>|null  $holidays
     */
    public function isNonWorkingDay(
        Carbon|string $date,
        array $weeklyHolidays = [],
        ?Collection $holidays = null,
        ?string $companyId = null,
    ): bool {
        $date = Carbon::parse($date)->startOfDay();
        $dayName = strtolower($date->format('l'));

        if ($weeklyHolidays === [] && $companyId !== null) {
            $weeklyHolidays = $this->weeklyHolidaysFor($companyId);
        }

        if (in_array($dayName, $weeklyHolidays, true)) {
            return true;
        }

        $holidays ??= $companyId !== null ? $this->holidaysFor($companyId) : collect();

        return $holidays->contains(fn (Holiday $holiday) => $holiday->occursOn($date));
    }

    /**
     * @return list<string>
     */
    public function weeklyHolidaysFor(string $companyId): array
    {
        $policy = HolidayPolicy::where('company_id', $companyId)->first();

        return array_map('strtolower', $policy?->weekly_holidays ?? []);
    }

    /**
     * @return Collection<int, Holiday>
     */
    public function holidaysFor(string $companyId): Collection
    {
        return Holiday::where('company_id', $companyId)->get();
    }
}
