<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('fcm:test {userId} {title} {body}', function (\App\Services\FcmService $fcmService, $userId, $title, $body) {
    if (!$fcmService->isConfigured()) {
        $this->error('FCM is not configured! FIREBASE_CREDENTIALS path is invalid or empty in .env');
        return;
    }

    $tokens = \App\Models\DeviceToken::where('user_id', $userId)->get();
    if ($tokens->isEmpty()) {
        $this->warn("No device tokens registered for user ID: {$userId}");
        return;
    }

    $this->info("Sending push notification to user {$userId} (found " . $tokens->count() . " tokens)...");
    $fcmService->notifyUser((int)$userId, $title, $body, ['type' => 'test']);
    $this->info('Done!');
})->purpose('Send a test FCM push notification to a user');

use Illuminate\Support\Facades\Schedule;

Schedule::command('appointments:send-reminders')->everyFiveMinutes();

// Abandoned checkouts would otherwise block the patient's only active request
// slot and distort the zone wait estimates.
Schedule::command('bookings:expire-unpaid')->everyFiveMinutes();

// Prune expired symptom voice notes from disk retention.
Schedule::command('aura:prune-symptom-audio')->daily();

// Send immunization milestone push notifications to parents.
Schedule::command('vaccines:send-age-alerts')->dailyAt('09:00')->onOneServer();

// Weekly professional settlements check (every Monday at 03:00).
Schedule::command('aura:payouts')->weeklyOn(1, '03:00')->onOneServer();

// Scheduler heartbeat for DiagnoseDeployment health check.
Schedule::call(function () {
    cache()->put('scheduler_heartbeat', now()->toIso8601String(), 900);
})->everyFiveMinutes();

// Daily prune for expired WebRTC video signals older than 24h.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('video_signals')
        ->where('created_at', '<', now()->subHours(24))
        ->delete();
})->daily();

