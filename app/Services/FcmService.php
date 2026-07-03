<?php

namespace App\Services;

use App\Models\DeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through the FCM HTTP v1 API. Silently no-ops
 * when Firebase credentials are not configured so every caller can fire
 * and forget.
 */
class FcmService
{
    public function isConfigured(): bool
    {
        $path = config('services.firebase.credentials');
        return !empty($path) && file_exists($path);
    }

    /**
     * Send a notification to every registered device of a user.
     */
    public function notifyUser(int $userId, string $title, string $body, array $data = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $userId)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $accessToken = $this->accessToken();
            $projectId = $this->projectId();
        } catch (\Throwable $e) {
            Log::warning('FCM credentials error', ['error' => $e->getMessage()]);
            return;
        }

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'data' => array_map('strval', $data),
                        ],
                    ]);

                if ($response->status() === 404 || $response->json('error.details.0.errorCode') === 'UNREGISTERED') {
                    // Stale token: the app was uninstalled or the token rotated
                    DeviceToken::where('token', $token)->delete();
                } elseif (!$response->successful()) {
                    Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->json()]);
                }
            } catch (\Throwable $e) {
                Log::warning('FCM unreachable', ['error' => $e->getMessage()]);
            }
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(50), function () {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                config('services.firebase.credentials')
            );

            return $credentials->fetchAuthToken()['access_token'];
        });
    }

    private function projectId(): string
    {
        $json = json_decode(file_get_contents(config('services.firebase.credentials')), true);
        return $json['project_id'];
    }
}
