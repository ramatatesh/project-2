<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Log;

class DeviceService
{
    public function register(User $user, string $fcmToken, ?string $platform = null, ?string $deviceName = null): UserDevice
    {
        $device = UserDevice::query()->where('fcm_token', $fcmToken)->first();

        if ($device) {
            $device->fill([
                'user_id' => $user->id,
                'platform' => $platform ?? $device->platform,
                'device_name' => $deviceName ?? $device->device_name,
                'is_active' => true,
            ]);
            $device->save();
        } else {
            $device = UserDevice::create([
                'user_id' => $user->id,
                'fcm_token' => $fcmToken,
                'platform' => $platform,
                'device_name' => $deviceName,
                'is_active' => true,
            ]);
        }

        // If evaluation (or other) pushes were skipped earlier because this user
        // had no token yet, retry them now that a device is active.
        try {
            app(NotificationService::class)->retryUnsentPushesForUser($user->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to retry unsent pushes after device register.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $device;
    }

    public function unregister(User $user, string $fcmToken): void
    {
        $updated = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('fcm_token', $fcmToken)
            ->update(['is_active' => false]);

        if ($updated === 0) {
            Log::info('FCM token unregister skipped: token not found for user.', [
                'user_id' => $user->id,
            ]);
        }
    }
}
