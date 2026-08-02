<?php

namespace App\Console\Commands;

use App\Services\EvaluationService;
use Illuminate\Console\Command;

class ExpirePendingEvaluationReviews extends Command
{
    protected $signature = 'evaluations:expire-pending-reviews';

    protected $description = 'Mark pending evaluation reviews as expired when their due date has passed.';

    public function handle(EvaluationService $evaluationService): int
    {
        $expired = $evaluationService->expirePendingReviews();

        $this->info("Expired {$expired} pending evaluation review(s).");

        return self::SUCCESS;
    }
}
