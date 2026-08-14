<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireOverdueSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire-overdue';

    protected $description = 'Mark overdue active subscriptions as expired and freeze their companies.';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $expired = $subscriptionService->expireOverdueSubscriptions();

        $this->info("Expired {$expired} overdue subscription(s).");

        return self::SUCCESS;
    }
}
