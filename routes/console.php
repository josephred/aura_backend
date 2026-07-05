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
