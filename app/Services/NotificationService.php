<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\EvaluationReview;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\OvertimeRequest;
use App\Models\SalaryAdvance;
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

        $title = __('New performance evaluation 📋');
        $body = $this->evaluationAssignedBody($review->review_type, $employeeName);

        $this->createAndDispatch(
            companyId: $review->company_id,
            userId: $review->reviewer_id,
            type: Notification::TYPE_EVALUATION_ASSIGNED,
            title: $title,
            body: $body,
            relatedId: $review->id,
            relatedTable: 'evaluation_reviews',
        );
    }

    /**
     * Notify employee on final leave outcomes:
     * - manager reject → immediate push
     * - HR approve / HR reject → push
     * Manager approve (forward to HR) must NOT notify.
     */
    public function notifyLeaveDecision(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['employee.user', 'leaveType']);

        $userId = $leaveRequest->employee?->user_id;
        if (! $userId) {
            return;
        }

        $status = $leaveRequest->status;
        $approved = $status === 'approved';
        $rejected = in_array($status, ['rejected_by_manager', 'rejected_by_hr'], true);

        if (! $approved && ! $rejected) {
            return;
        }

        $leaveTypeName = $this->sanitizeDisplayName($leaveRequest->leaveType?->name);

        $title = $approved ? __('Leave request approved ✅') : __('Leave request rejected ❌');
        $body = $approved
            ? ($leaveTypeName
                ? __('Your leave request (:type) has been approved. You can view the details in the app.', ['type' => $leaveTypeName])
                : __('Your leave request has been approved. You can view the details in the app.'))
            : ($leaveTypeName
                ? __('Your leave request (:type) has been rejected. Please open the app for details.', ['type' => $leaveTypeName])
                : __('Your leave request has been rejected. Please open the app for details.'));

        $this->createAndDispatch(
            companyId: $leaveRequest->company_id,
            userId: $userId,
            type: $approved ? Notification::TYPE_LEAVE_APPROVED : Notification::TYPE_LEAVE_REJECTED,
            title: $title,
            body: $body,
            relatedId: $leaveRequest->id,
            relatedTable: 'leave_requests',
        );
    }

    /**
     * Notify employee on final overtime outcomes:
     * - manager reject → immediate push
     * - HR approve / HR reject → push
     * Manager approve (forward to HR) must NOT notify.
     */
    public function notifyOvertimeDecision(OvertimeRequest $overtimeRequest): void
    {
        $overtimeRequest->loadMissing(['employee.user']);

        $userId = $overtimeRequest->employee?->user_id;
        if (! $userId) {
            return;
        }

        $status = $overtimeRequest->status;
        $approved = $status === OvertimeRequest::STATUS_APPROVED;
        $rejected = in_array($status, [
            OvertimeRequest::STATUS_REJECTED_BY_MANAGER,
            OvertimeRequest::STATUS_REJECTED_BY_HR,
        ], true);

        if (! $approved && ! $rejected) {
            return;
        }

        $title = $approved ? __('Overtime request approved ✅') : __('Overtime request rejected ❌');
        $body = $approved
            ? __('Your overtime request has been approved. You can view the details in the app.')
            : __('Your overtime request has been rejected. Please open the app for details.');

        $this->createAndDispatch(
            companyId: $overtimeRequest->company_id,
            userId: $userId,
            type: $approved ? Notification::TYPE_OVERTIME_APPROVED : Notification::TYPE_OVERTIME_REJECTED,
            title: $title,
            body: $body,
            relatedId: $overtimeRequest->id,
            relatedTable: 'overtime_requests',
        );
    }

    /**
     * Notify employee on final advance outcomes:
     * - manager reject → immediate push
     * - HR approve / HR reject → push
     * Manager approve (forward to HR) must NOT notify.
     */
    public function notifyAdvanceDecision(SalaryAdvance $advance): void
    {
        $advance->loadMissing(['employee.user']);

        $userId = $advance->employee?->user_id;
        if (! $userId) {
            return;
        }

        $status = $advance->status;
        $approved = $status === SalaryAdvance::STATUS_APPROVED;
        $rejected = in_array($status, [
            SalaryAdvance::STATUS_REJECTED_BY_MANAGER,
            SalaryAdvance::STATUS_REJECTED_BY_HR,
        ], true);

        if (! $approved && ! $rejected) {
            return;
        }

        $title = $approved ? __('Salary advance request approved ✅') : __('Salary advance request rejected ❌');
        $body = $approved
            ? __('Your salary advance request has been approved. You can view the details in the app.')
            : __('Your salary advance request has been rejected. Please open the app for details.');

        $this->createAndDispatch(
            companyId: $advance->company_id,
            userId: $userId,
            type: $approved ? Notification::TYPE_ADVANCE_APPROVED : Notification::TYPE_ADVANCE_REJECTED,
            title: $title,
            body: $body,
            relatedId: $advance->id,
            relatedTable: 'salary_advances',
        );
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
            'title' => (string) $notification->title,
            'body' => (string) ($notification->body ?? ''),
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

    private function createAndDispatch(
        string $companyId,
        string $userId,
        string $type,
        string $title,
        string $body,
        string $relatedId,
        string $relatedTable,
    ): void {
        $notification = Notification::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'related_id' => $relatedId,
            'related_table' => $relatedTable,
            'is_read' => false,
            'delivery_channel' => Notification::CHANNEL_PUSH,
            'push_sent' => false,
            'push_sent_at' => null,
        ]);

        SendPushNotificationJob::dispatch($notification->id);
    }

    private function evaluationAssignedBody(?string $reviewType, ?string $employeeName): string
    {
        return match ($reviewType) {
            EvaluationReview::TYPE_SELF => __('You have a new self performance evaluation. Please open the app and complete it.'),
            EvaluationReview::TYPE_MANAGER => $employeeName
                ? __('You have a new manager performance evaluation for :name. Please open the app and complete it.', ['name' => $employeeName])
                : __('You have a new manager performance evaluation. Please open the app and complete it.'),
            EvaluationReview::TYPE_PEER => $employeeName
                ? __('You have a new peer evaluation for :name. Please open the app and complete it.', ['name' => $employeeName])
                : __('You have a new peer evaluation. Please open the app and complete it.'),
            default => __('You have a new performance evaluation. Please open the app and complete it.'),
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
