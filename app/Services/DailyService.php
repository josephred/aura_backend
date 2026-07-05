<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daily.co video rooms for telemedicine appointments.
 *
 * Rooms are private: joining always requires a meeting token issued by us,
 * so only the patient and the clinical staff can enter a consultation.
 */
class DailyService
{
    private const API_BASE = 'https://api.daily.co/v1';

    public function isConfigured(): bool
    {
        return !empty(config('services.daily.api_key'));
    }

    /**
     * Create a private room for an appointment.
     * Returns ['name' => ..., 'url' => ...] or null on failure.
     */
    public function createRoom(string $name, Carbon $expiresAt): ?array
    {
        try {
            $response = Http::withToken(config('services.daily.api_key'))
                ->timeout(10)
                ->post(self::API_BASE . '/rooms', [
                    'name' => $name,
                    'privacy' => 'private',
                    'properties' => [
                        'exp' => $expiresAt->timestamp,
                        'max_participants' => 4,
                        'enable_chat' => true,
                        'enable_screenshare' => false,
                        'eject_at_room_exp' => true,
                        'lang' => 'es',
                    ],
                ]);

            if ($response->successful()) {
                return [
                    'name' => $response->json('name'),
                    'url' => $response->json('url'),
                ];
            }

            Log::warning('Daily room creation failed', [
                'room' => $name,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Daily unreachable while creating room', [
                'room' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Issue a meeting token to join a private room.
     * Owners (clinical staff) get moderation controls.
     */
    public function createMeetingToken(
        string $roomName,
        string $userName,
        bool $isOwner,
        Carbon $expiresAt,
    ): ?string {
        try {
            $response = Http::withToken(config('services.daily.api_key'))
                ->timeout(10)
                ->post(self::API_BASE . '/meeting-tokens', [
                    'properties' => [
                        'room_name' => $roomName,
                        'user_name' => $userName,
                        'is_owner' => $isOwner,
                        'exp' => $expiresAt->timestamp,
                    ],
                ]);

            if ($response->successful()) {
                return $response->json('token');
            }

            Log::warning('Daily meeting token failed', [
                'room' => $roomName,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Daily unreachable while creating token', [
                'room' => $roomName,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
