<?php

namespace App\Services;

use App\Models\UserDevice;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class FcmService
{
    public function isConfigured(): bool
    {
        $path = $this->credentialsPath();

        return $path !== null && is_file($path) && is_readable($path);
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     * @return array{sent: int, failed: int}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $sent = 0;
        $failed = 0;

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        if (! $this->isConfigured()) {
            Log::info('FCM skipped: Firebase credentials are not configured.');

            return ['sent' => 0, 'failed' => count($tokens)];
        }

        try {
            $messaging = (new Factory)
                ->withServiceAccount($this->credentialsPath())
                ->createMessaging();
        } catch (\Throwable $e) {
            Log::error('FCM client initialization failed.', ['error' => $e->getMessage()]);

            return ['sent' => 0, 'failed' => count($tokens)];
        }

        foreach ($tokens as $token) {
            try {
                $stringData = [];
                foreach ($data as $key => $value) {
                    $stringData[(string) $key] = is_scalar($value) || $value === null
                        ? (string) $value
                        : json_encode($value);
                }

                // Ensure Flutter can always rebuild a local notification from data
                // even if the system tray notification payload is stripped.
                $stringData['title'] = $stringData['title'] ?? $title;
                $stringData['body'] = $stringData['body'] ?? $body;

                $message = CloudMessage::new()
                    ->withTarget('token', $token)
                    ->withNotification(FcmNotification::create($title, $body))
                    ->withData($stringData)
                    ->withAndroidConfig(AndroidConfig::fromArray([
                        'priority' => 'high',
                        'notification' => [
                            // Must match Flutter channel + AndroidManifest default channel.
                            'channel_id' => 'khibrat_default',
                            'sound' => 'default',
                        ],
                    ]))
                    ->withApnsConfig(ApnsConfig::fromArray([
                        'headers' => ['apns-priority' => '10'],
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ]));

                $messaging->send($message);
                $sent++;
                Log::info('FCM send succeeded.', [
                    'token_prefix' => substr($token, 0, 12),
                ]);
            } catch (NotFound|InvalidMessage $e) {
                $failed++;
                $this->deactivateToken($token);
                Log::info('FCM token invalidated and deactivated.', [
                    'token_prefix' => substr($token, 0, 12),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('FCM send failed for a token.', [
                    'token_prefix' => substr($token, 0, 12),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function deactivateToken(string $token): void
    {
        UserDevice::query()
            ->where('fcm_token', $token)
            ->update(['is_active' => false]);
    }

    private function credentialsPath(): ?string
    {
        $path = config('services.firebase.credentials');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' );
    }
}
