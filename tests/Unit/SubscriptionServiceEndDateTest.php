<?php

namespace Tests\Unit;

use App\Services\SubscriptionService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * True unit test: calculateSubscriptionEndDateFrom() is pure Carbon arithmetic over its
 * two parameters - no database, no HTTP, no Stripe call (that lives in a different,
 * untouched method on the same service).
 */
class SubscriptionServiceEndDateTest extends TestCase
{
    public function test_monthly_billing_adds_one_month(): void
    {
        $service = new SubscriptionService();

        $end = $service->calculateSubscriptionEndDateFrom(Carbon::parse('2026-01-15'), 'month');

        $this->assertSame('2026-02-15', $end->toDateString());
    }

    public function test_quarterly_billing_adds_three_months(): void
    {
        $service = new SubscriptionService();

        $end = $service->calculateSubscriptionEndDateFrom(Carbon::parse('2026-01-15'), 'quarter');

        $this->assertSame('2026-04-15', $end->toDateString());
    }

    public function test_yearly_billing_adds_one_year(): void
    {
        $service = new SubscriptionService();

        $end = $service->calculateSubscriptionEndDateFrom(Carbon::parse('2026-01-15'), 'year');

        $this->assertSame('2027-01-15', $end->toDateString());
    }

    public function test_unrecognized_or_missing_billing_period_falls_back_to_monthly(): void
    {
        $service = new SubscriptionService();

        $end = $service->calculateSubscriptionEndDateFrom(Carbon::parse('2026-01-15'), null);

        $this->assertSame('2026-02-15', $end->toDateString());
    }
}
