<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies social provider credentials server-side. User identity
 * (provider id, email, name) is always taken from the provider's own
 * verification response — never from the client request.
 */
class SocialTokenVerifier
{
    public function isProviderConfigured(string $provider): bool
    {
        return match ($provider) {
            'google' => config('services.google.client_id') !== null
                && config('services.google.client_id') !== false,
            'facebook' => !empty(config('services.facebook.app_id'))
                && !empty(config('services.facebook.app_secret')),
            default => false,
        };
    }

    /**
     * Verify a provider credential.
     * Returns ['provider_id' => ..., 'email' => ..., 'name' => ...] or null.
     */
    public function verify(string $provider, string $credential): ?array
    {
        return match ($provider) {
            'google' => $this->verifyGoogle($credential),
            'facebook' => $this->verifyFacebook($credential),
            default => null,
        };
    }

    /**
     * Validate a Google Sign-In id_token against Google's tokeninfo
     * endpoint (signature checked by Google) and our own client id.
     */
    private function verifyGoogle(string $idToken): ?array
    {
        try {
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

            if (!$response->successful()) {
                Log::warning('Google tokeninfo endpoint failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            $allowedAudiences = array_filter([
                config('services.google.client_id'),
                env('GOOGLE_CLIENT_ID'),
                '932839695266-7ocgk36gsgifbl7etokb67v2mc90ub5v.apps.googleusercontent.com',
                '932839695266-msq5o5psrfjfh72blnppfqerh66jpclb.apps.googleusercontent.com',
            ]);

            $aud = $data['aud'] ?? '';
            $azp = $data['azp'] ?? '';
            $audienceOk = in_array($aud, $allowedAudiences, true)
                || in_array($azp, $allowedAudiences, true)
                || str_starts_with($aud, '932839695266')
                || str_starts_with($azp, '932839695266');

            $emailVerified = filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!$audienceOk || !$emailVerified || empty($data['sub']) || empty($data['email'])) {
                Log::warning('Google token rejected', [
                    'aud' => $aud,
                    'azp' => $azp,
                    'aud_ok' => $audienceOk,
                    'email_verified' => $emailVerified,
                ]);
                return null;
            }

            return [
                'provider_id' => $data['sub'],
                'email' => $data['email'],
                'name' => $data['name'] ?? $data['email'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Google token verification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate a Facebook access token via debug_token using the app
     * access token, then fetch the profile with the user token.
     */
    private function verifyFacebook(string $accessToken): ?array
    {
        try {
            $appId = config('services.facebook.app_id');
            $appToken = $appId . '|' . config('services.facebook.app_secret');

            $debug = Http::timeout(10)->get('https://graph.facebook.com/debug_token', [
                'input_token' => $accessToken,
                'access_token' => $appToken,
            ]);

            $debugData = $debug->json('data');
            if (!$debug->successful()
                || empty($debugData['is_valid'])
                || ($debugData['app_id'] ?? null) !== $appId) {
                Log::warning('Facebook token rejected', ['debug' => $debugData]);
                return null;
            }

            $profile = Http::timeout(10)->get('https://graph.facebook.com/me', [
                'fields' => 'id,name,email',
                'access_token' => $accessToken,
            ]);

            $profileData = $profile->json();
            if (!$profile->successful() || empty($profileData['id']) || empty($profileData['email'])) {
                // An email is required to link accounts safely
                return null;
            }

            return [
                'provider_id' => $profileData['id'],
                'email' => $profileData['email'],
                'name' => $profileData['name'] ?? $profileData['email'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Facebook token verification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
