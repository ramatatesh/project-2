<?php

namespace App\Services\Ai\Context;

use App\Contracts\Ai\EmployeeContextProvider;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Ai\Concerns\MatchesAssistantTopics;
use Carbon\Carbon;

/**
 * Company holidays for the authenticated employee's company only
 * (same visibility as GET /api/employee/company-holidays).
 */
class CompanyHolidayContextProvider implements EmployeeContextProvider
{
    use MatchesAssistantTopics;

    public function key(): string
    {
        return 'company_holidays';
    }

    public function supports(string $message): bool
    {
        return $this->matchesAny($message, [
            'عطلة', 'عطل', 'عطلات', 'holiday', 'holidays',
            'عيد', 'رسمية', 'هل اليوم عطلة', 'دوام في',
        ]);
    }

    public function build(Employee $employee, User $user): array
    {
        $companyId = $employee->company_id;
        $today = Carbon::today();
        $year = $today->year;
        $month = $today->month;

        $holidays = Holiday::where('company_id', $companyId)
            ->orderBy('start_date')
            ->get();

        $mapHoliday = fn (Holiday $holiday) => [
            'name' => $holiday->name,
            'start_date' => optional($holiday->start_date)?->toDateString(),
            'end_date' => optional($holiday->end_date)?->toDateString(),
            'repeats_annually' => (bool) $holiday->repeats_annually,
            'occurs_today' => $holiday->occursOn($today),
        ];

        $thisMonth = $holidays->filter(function (Holiday $holiday) use ($year, $month) {
            if (! $holiday->start_date) {
                return false;
            }

            if ($holiday->repeats_annually) {
                return (int) $holiday->start_date->month === $month
                    || ($holiday->end_date && (int) $holiday->end_date->month === $month);
            }

            return ((int) $holiday->start_date->year === $year && (int) $holiday->start_date->month === $month)
                || ($holiday->end_date && (int) $holiday->end_date->year === $year && (int) $holiday->end_date->month === $month);
        })->values();

        $thisYear = $holidays->filter(function (Holiday $holiday) use ($year) {
            if (! $holiday->start_date) {
                return false;
            }

            if ($holiday->repeats_annually) {
                return true;
            }

            return (int) $holiday->start_date->year === $year
                || ($holiday->end_date && (int) $holiday->end_date->year === $year);
        })->values();

        $upcoming = $holidays
            ->filter(function (Holiday $holiday) use ($today) {
                if ($holiday->repeats_annually && $holiday->start_date) {
                    $next = $holiday->start_date->copy()->year($today->year);
                    if ($next->lt($today)) {
                        $next->addYear();
                    }

                    return $next->gte($today);
                }

                $end = $holiday->end_date ?? $holiday->start_date;

                return $end && $end->gte($today);
            })
            ->sortBy(function (Holiday $holiday) use ($today) {
                if ($holiday->repeats_annually && $holiday->start_date) {
                    $next = $holiday->start_date->copy()->year($today->year);
                    if ($next->lt($today)) {
                        $next->addYear();
                    }

                    return $next->timestamp;
                }

                return optional($holiday->start_date)->timestamp ?? PHP_INT_MAX;
            })
            ->values();

        $next = $upcoming->first();

        return [
            'today' => [
                'date' => $today->toDateString(),
                'is_holiday' => $holidays->contains(fn (Holiday $h) => $h->occursOn($today)),
                'holiday_names' => $holidays
                    ->filter(fn (Holiday $h) => $h->occursOn($today))
                    ->pluck('name')
                    ->values()
                    ->all(),
            ],
            'next_holiday' => $next ? $mapHoliday($next) : null,
            'holidays_this_month' => $thisMonth->map($mapHoliday)->all(),
            'holidays_this_year' => $thisYear->map($mapHoliday)->all(),
            'upcoming_holidays' => $upcoming->take(10)->map($mapHoliday)->all(),
            'all_configured_holidays' => $holidays->map($mapHoliday)->values()->all(),
        ];
    }
}
