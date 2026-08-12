<?php

namespace App\Services\Ai\Concerns;

trait MatchesAssistantTopics
{
    /**
     * Simple keyword/topic match (Arabic + English). Security does not depend on this —
     * each provider still scopes data to the authenticated employee/company.
     *
     * @param  list<string>  $keywords
     */
    protected function matchesAny(string $message, array $keywords): bool
    {
        $haystack = mb_strtolower(trim($message));

        if ($haystack === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            $needle = mb_strtolower(trim($keyword));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
