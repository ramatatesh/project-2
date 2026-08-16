<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\EvaluationReview;
use App\Models\Notification;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function notifyEvaluationAssigned(EvaluationReview $review): void
    {
        $review->loadMissing(['employee.user', 'cycle']);

        if (! $review->reviewer_id) {
            return;
        }

        $employeeName = $this->sanitizeDisplayName($review->employee?->user?->full_name);

        $title = 'تقييم أداء جديد 📋';
        $body = $this->evaluationAssignedBody($review->review_type, $employeeName);

        $notification = Notification::create([
            'company_id' => $review->company_id,
            'user_id' => $review->reviewer_id,
            'type' => Notification::TYPE_EVALUATION_ASSIGNED,
            'title' => $title,
            'body' => $body,
            'related_id' => $review->id,
            'related_table' => 'evaluation_reviews',
            'is_read' => false,
            'delivery_channel' => Notification::CHANNEL_PUSH,
            'push_sent' => false,
            'push_sent_at' => null,
        ]);

        SendPushNotificationJob::dispatch($notification->id);
    }

    /**
     * When a device token is registered after a push was skipped, re-queue
     * unsent push notifications for that user (evaluation_assigned, etc.).
     */
    public function retryUnsentPushesForUser(string $userId): void
    {
        $notifications = Notification::query()
            ->where('user_id', $userId)
            ->where('delivery_channel', Notification::CHANNEL_PUSH)
            ->where('push_sent', false)
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id']);

        foreach ($notifications as $notification) {
            SendPushNotificationJob::dispatch($notification->id);
        }

        if ($notifications->isNotEmpty()) {
            Log::info('Re-queued unsent push notifications after device register.', [
                'user_id' => $userId,
                'count' => $notifications->count(),
            ]);
        }
    }

    public function sendPush(Notification $notification): void
    {
        $tokens = UserDevice::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            Log::info('Push skipped: no active FCM tokens for user.', [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
            ]);

            return;
        }

        $data = [
            'type' => (string) $notification->type,
            'notification_id' => (string) $notification->id,
            'related_id' => (string) ($notification->related_id ?? ''),
            'related_table' => (string) ($notification->related_table ?? ''),
            'review_id' => $notification->related_table === 'evaluation_reviews'
                ? (string) ($notification->related_id ?? '')
                : '',
        ];

        Log::info('FCM push attempt.', [
            'notification_id' => $notification->id,
            'user_id' => $notification->user_id,
            'type' => $notification->type,
            'related_id' => $notification->related_id,
            'related_table' => $notification->related_table,
            'token_count' => count($tokens),
            'payload_data' => $data,
        ]);

        $result = app(FcmService::class)->sendToTokens(
            $tokens,
            $notification->title,
            (string) $notification->body,
            $data
        );

        Log::info('FCM push result.', [
            'notification_id' => $notification->id,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ]);

        if ($result['sent'] > 0) {
            $notification->forceFill([
                'push_sent' => true,
                'push_sent_at' => now(),
            ])->save();
        }
    }

    private function evaluationAssignedBody(?string $reviewType, ?string $employeeName): string
    {
        return match ($reviewType) {
            EvaluationReview::TYPE_SELF => 'لديك تقييم أداء ذاتي جديد. يرجى فتح التطبيق وإكمال التقييم.',
            EvaluationReview::TYPE_MANAGER => $employeeName
                ? "لديك تقييم أداء جديد كمدير للموظف {$employeeName}. يرجى فتح التطبيق وإكمال التقييم."
                : 'لديك تقييم أداء جديد كمدير. يرجى فتح التطبيق وإكمال التقييم.',
            EvaluationReview::TYPE_PEER => $employeeName
                ? "لديك تقييم زميل جديد للموظف {$employeeName}. يرجى فتح التطبيق وإكمال التقييم."
                : 'لديك تقييم زميل جديد. يرجى فتح التطبيق وإكمال التقييم.',
            default => 'لديك تقييم أداء جديد. يرجى فتح التطبيق وإكمال التقييم.',
        };
    }

    /**
     * Keep user-facing copy free of Swagger/schema placeholders (e.g. "string").
     */
    private function sanitizeDisplayName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        if ($trimmed === '' || strcasecmp($trimmed, 'string') === 0) {
            return null;
        }

        return $trimmed;
    }
}
