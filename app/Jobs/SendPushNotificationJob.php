<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $notificationId) {}

    public function handle(NotificationService $notificationService): void
    {
        try {
            $notification = Notification::query()->find($this->notificationId);

            if (! $notification) {
                Log::info('Push job skipped: notification no longer exists.', [
                    'notification_id' => $this->notificationId,
                ]);

                return;
            }

            $notificationService->sendPush($notification);
        } catch (\Throwable $e) {
            Log::error('Push notification send failed without affecting the source action.', [
                'notification_id' => $this->notificationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Push notification job failed.', [
            'notification_id' => $this->notificationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
