<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\FcmService;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send FCM reminders for appointments starting within 24 hours and within 1 hour';

    public function handle(FcmService $fcm): int
    {
        $sent = 0;

        // 24-hour reminders
        $upcoming = Appointment::with('professional')
            ->where('status', 'confirmed')
            ->whereNull('reminder_24h_sent_at')
            ->whereBetween('scheduled_at', [now(), now()->addHours(24)])
            ->get();

        foreach ($upcoming as $appointment) {
            $fcm->notifyUser(
                $appointment->user_id,
                'Recordatorio de cita',
                "Mañana tienes una cita con {$appointment->professional?->name} a las {$appointment->scheduled_at->format('H:i')}.",
                ['appointment_id' => $appointment->id, 'type' => 'appointment'],
            );
            $appointment->update(['reminder_24h_sent_at' => now()]);
            $sent++;
        }

        // 1-hour reminders
        $imminent = Appointment::with('professional')
            ->where('status', 'confirmed')
            ->whereNull('reminder_1h_sent_at')
            ->whereBetween('scheduled_at', [now(), now()->addHour()])
            ->get();

        foreach ($imminent as $appointment) {
            $fcm->notifyUser(
                $appointment->user_id,
                'Tu cita es en menos de una hora',
                "Cita con {$appointment->professional?->name} a las {$appointment->scheduled_at->format('H:i')}. ¡Te esperamos!",
                ['appointment_id' => $appointment->id, 'type' => 'appointment'],
            );
            $appointment->update(['reminder_1h_sent_at' => now()]);
            $sent++;
        }

        // Drop stale WebRTC signaling rows from past calls
        \App\Models\VideoSignal::where('created_at', '<', now()->subDay())->delete();

        $this->info("Reminders sent: $sent");

        return self::SUCCESS;
    }
}
