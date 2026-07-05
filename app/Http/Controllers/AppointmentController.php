<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Professional;
use App\Services\DailyService;
use App\Services\FcmService;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    private const MAX_DAYS_AHEAD = 30;

    /**
     * Public catalog of active professionals.
     */
    public function professionals(): JsonResponse
    {
        $professionals = Professional::where('active', true)
            ->orderBy('specialty')
            ->orderBy('name')
            ->get();

        return response()->json($professionals);
    }

    /**
     * Available slots for a professional on a given date.
     */
    public function slots(Request $request, string $professionalId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $professional = Professional::where('active', true)->find($professionalId);
        if (!$professional) {
            return response()->json(['error' => 'Profesional no encontrado'], 404);
        }

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay();
        if ($date->lt(now()->startOfDay()) || $date->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            return response()->json(['slots' => []]);
        }

        return response()->json([
            'date' => $validated['date'],
            'duration_minutes' => $professional->consultation_duration_minutes,
            'slots' => $this->availableSlots($professional, $date),
        ]);
    }

    /**
     * Compute the free slots of a professional for a date, from their weekly
     * schedule minus already-booked appointments and past times.
     * Returns ISO datetime strings.
     */
    private function availableSlots(Professional $professional, Carbon $date): array
    {
        $duration = max(5, (int) $professional->consultation_duration_minutes);

        $blocks = $professional->schedules()
            ->where('day_of_week', $date->isoWeekday())
            ->orderBy('start_time')
            ->get();

        if ($blocks->isEmpty()) {
            return [];
        }

        $taken = Appointment::where('professional_id', $professional->id)
            ->whereIn('status', ['pending_payment', 'confirmed'])
            ->whereBetween('scheduled_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->pluck('scheduled_at')
            ->map(fn ($dt) => Carbon::parse($dt)->format('Y-m-d H:i'))
            ->all();

        $minimumStart = now()->addMinutes(15);
        $slots = [];

        foreach ($blocks as $block) {
            $cursor = $date->copy()->setTimeFromTimeString($block->start_time);
            $blockEnd = $date->copy()->setTimeFromTimeString($block->end_time);

            while ($cursor->copy()->addMinutes($duration)->lte($blockEnd)) {
                $isTaken = in_array($cursor->format('Y-m-d H:i'), $taken);
                if (!$isTaken && $cursor->gt($minimumStart)) {
                    $slots[] = $cursor->toIso8601String();
                }
                $cursor->addMinutes($duration);
            }
        }

        return $slots;
    }

    /**
     * Book an appointment on a free slot.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'professional_id' => 'required|string|exists:professionals,id',
            'scheduled_at' => 'required|date',
            'dependent_id' => 'nullable|string|exists:dependents,id',
            'reason' => 'nullable|string|max:500',
            'type' => 'nullable|string|in:presencial,video',
        ]);

        $professional = Professional::where('active', true)->find($validated['professional_id']);
        if (!$professional) {
            return response()->json(['error' => 'Profesional no disponible'], 422);
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at'])->seconds(0);

        if ($scheduledAt->isPast() || $scheduledAt->gt(now()->addDays(self::MAX_DAYS_AHEAD)->endOfDay())) {
            return response()->json(['error' => 'Fecha fuera del rango permitido'], 422);
        }

        // The requested time must be one of the currently free slots
        $freeSlots = $this->availableSlots($professional, $scheduledAt->copy()->startOfDay());
        $requested = $scheduledAt->format('Y-m-d H:i');
        $isFree = collect($freeSlots)->contains(
            fn ($iso) => Carbon::parse($iso)->format('Y-m-d H:i') === $requested
        );

        if (!$isFree) {
            return response()->json(['error' => 'El horario seleccionado ya no está disponible'], 409);
        }

        $appointment = DB::transaction(function () use ($validated, $professional, $scheduledAt) {
            // Re-check collision inside the transaction to close the race window
            $collision = Appointment::where('professional_id', $professional->id)
                ->whereIn('status', ['pending_payment', 'confirmed'])
                ->where('scheduled_at', $scheduledAt)
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                return null;
            }

            return Appointment::create([
                'id' => 'apt_' . now()->timestamp . '_' . Str::lower(Str::random(4)),
                'user_id' => auth()->id(),
                'professional_id' => $professional->id,
                'dependent_id' => $validated['dependent_id'] ?? null,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $professional->consultation_duration_minutes,
                'reason' => $validated['reason'] ?? null,
                'type' => $validated['type'] ?? 'presencial',
                'status' => 'pending_payment',
                'price' => $professional->consultation_price,
            ]);
        });

        if (!$appointment) {
            return response()->json(['error' => 'El horario seleccionado ya no está disponible'], 409);
        }

        $mercadoPago = app(MercadoPagoService::class);
        if ($mercadoPago->isConfigured()) {
            $preference = $mercadoPago->createGenericPreference(
                $appointment->id,
                $professional->id,
                "Aura Salud — Cita con {$professional->name}",
                "Consulta de {$professional->specialty}",
                (float) $appointment->price,
                ['appointment_id' => $appointment->id, 'user_id' => $appointment->user_id],
            );
            if ($preference) {
                $appointment->update([
                    'payment_preference_id' => $preference['id'],
                    'payment_url' => $preference['init_point'],
                    'payment_status' => 'pending',
                ]);
                return response()->json($this->present($appointment->fresh()), 201);
            }
        }

        // Gateway not configured or unreachable: confirm immediately
        $this->confirmAppointment($appointment);

        return response()->json($this->present($appointment->fresh()), 201);
    }

    /**
     * Confirm an appointment and notify the patient.
     */
    private function confirmAppointment(Appointment $appointment): void
    {
        $appointment->update(['status' => 'confirmed']);

        // Video consultations get a private Daily room living until well
        // after the scheduled end
        if ($appointment->type === 'video' && empty($appointment->video_room_name)) {
            $daily = app(DailyService::class);
            if ($daily->isConfigured()) {
                $room = $daily->createRoom(
                    $appointment->id,
                    $appointment->scheduled_at->copy()->addMinutes($appointment->duration_minutes + 60),
                );
                if ($room) {
                    $appointment->update([
                        'video_room_name' => $room['name'],
                        'video_room_url' => $room['url'],
                    ]);
                }
            }
        }

        $professional = $appointment->professional;
        $when = $this->formatDateEs($appointment->scheduled_at);
        $kind = $appointment->type === 'video' ? 'videoconsulta' : 'cita';

        app(FcmService::class)->notifyUser(
            $appointment->user_id,
            'Cita confirmada',
            "Tu $kind con {$professional->name} quedó agendada para el $when.",
            ['appointment_id' => $appointment->id, 'type' => 'appointment'],
        );
    }

    /**
     * Join window: from 15 minutes before the start until 30 minutes
     * after the scheduled end.
     */
    public static function isJoinable(Appointment $appointment): bool
    {
        $start = $appointment->scheduled_at;
        $end = $start->copy()->addMinutes($appointment->duration_minutes + 30);

        return now()->gte($start->copy()->subMinutes(15)) && now()->lte($end);
    }

    /**
     * Meeting credentials for the patient to join their video consultation.
     */
    public function videoJoin(string $id): JsonResponse
    {
        $appointment = Appointment::where('user_id', auth()->id())->find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        if ($appointment->type !== 'video' || $appointment->status !== 'confirmed') {
            return response()->json(['error' => 'Esta cita no tiene videoconsulta activa'], 422);
        }

        if (empty($appointment->video_room_url)) {
            return response()->json(['error' => 'La sala de video aún no está disponible'], 503);
        }

        if (!self::isJoinable($appointment)) {
            $when = $this->formatDateEs($appointment->scheduled_at);
            return response()->json(['error' => "Podrás unirte 15 minutos antes de tu cita ($when)."], 422);
        }

        $token = app(DailyService::class)->createMeetingToken(
            $appointment->video_room_name,
            auth()->user()->name,
            false,
            now()->addHours(2),
        );

        if (!$token) {
            return response()->json(['error' => 'No se pudo generar el acceso. Intenta de nuevo.'], 503);
        }

        return response()->json([
            'room_url' => $appointment->video_room_url,
            'token' => $token,
            'join_url' => $appointment->video_room_url . '?t=' . $token,
        ]);
    }

    /**
     * Approve an appointment after its payment is confirmed. Idempotent.
     */
    public function approvePayment(Appointment $appointment, string $paymentId): void
    {
        if ($appointment->payment_status === 'approved') {
            return;
        }

        $appointment->update([
            'payment_status' => 'approved',
            'payment_id' => $paymentId,
        ]);

        if ($appointment->status === 'pending_payment') {
            $this->confirmAppointment($appointment);
        }
    }

    /**
     * Check (and refresh from Mercado Pago) the payment status of an appointment.
     */
    public function paymentStatus(string $id): JsonResponse
    {
        $appointment = Appointment::where('user_id', auth()->id())->find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        if ($appointment->status === 'pending_payment') {
            $mercadoPago = app(MercadoPagoService::class);
            if ($mercadoPago->isConfigured()) {
                $payment = $mercadoPago->findApprovedPayment($appointment->id);
                if ($payment) {
                    $this->approvePayment($appointment, (string) $payment['id']);
                }
            }
        }

        return response()->json($this->present($appointment->fresh()));
    }

    /**
     * The user's appointments: upcoming first, then past.
     */
    public function index(): JsonResponse
    {
        $appointments = Appointment::with('professional')
            ->where('user_id', auth()->id())
            ->orderBy('scheduled_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($apt) => $this->present($apt));

        return response()->json($appointments);
    }

    /**
     * Cancel a future appointment.
     */
    public function cancel(string $id): JsonResponse
    {
        $appointment = Appointment::where('user_id', auth()->id())->find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        if (!in_array($appointment->status, ['pending_payment', 'confirmed'])) {
            return response()->json(['error' => 'La cita no se puede cancelar'], 422);
        }

        if ($appointment->scheduled_at->isPast()) {
            return response()->json(['error' => 'La cita ya comenzó'], 422);
        }

        $appointment->update(['status' => 'cancelled']);

        return response()->json($this->present($appointment->fresh()));
    }

    /**
     * Serialize an appointment with its professional for API responses.
     */
    private function present(Appointment $appointment): array
    {
        $professional = $appointment->professional;

        return [
            'id' => $appointment->id,
            'professional_id' => $appointment->professional_id,
            'professional_name' => $professional?->name,
            'specialty' => $professional?->specialty,
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
            'duration_minutes' => $appointment->duration_minutes,
            'reason' => $appointment->reason,
            'type' => $appointment->type ?? 'presencial',
            'has_video_room' => !empty($appointment->video_room_url),
            'status' => $appointment->status,
            'price' => $appointment->price,
            'payment_url' => $appointment->payment_url,
            'payment_status' => $appointment->payment_status,
        ];
    }

    /**
     * Human date in Spanish, e.g. "viernes 10 de julio a las 15:30".
     */
    private function formatDateEs(Carbon $date): string
    {
        $days = ['Monday' => 'lunes', 'Tuesday' => 'martes', 'Wednesday' => 'miércoles',
            'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sábado', 'Sunday' => 'domingo'];
        $months = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril',
            'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto',
            'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];

        $day = $days[$date->format('l')] ?? $date->format('l');
        $month = $months[$date->format('F')] ?? $date->format('F');

        return "$day {$date->day} de $month a las {$date->format('H:i')}";
    }
}
