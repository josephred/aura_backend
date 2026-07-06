<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Dependent;
use App\Models\ProfessionalSchedule;
use App\Models\VideoSignal;
use App\Services\FcmService;
use App\Services\WebRtcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorAgendaController extends Controller
{
    /**
     * Display the agenda page.
     */
    public function index()
    {
        return view('doctor.agenda');
    }

    /**
     * Upcoming and recent appointments for the staff portal.
     */
    public function appointments(): JsonResponse
    {
        $appointments = Appointment::with(['professional', 'user'])
            ->where('scheduled_at', '>=', now()->subDays(7))
            ->orderBy('scheduled_at')
            ->limit(200)
            ->get()
            ->map(function ($apt) {
                $patient = $apt->user?->name;
                if ($apt->dependent_id) {
                    $dependent = Dependent::find($apt->dependent_id);
                    if ($dependent) {
                        $patient = "{$dependent->name} ({$dependent->relationship})";
                    }
                }

                return [
                    'id' => $apt->id,
                    'scheduled_at' => $apt->scheduled_at->toIso8601String(),
                    'duration_minutes' => $apt->duration_minutes,
                    'patient_name' => $patient,
                    'professional_name' => $apt->professional?->name,
                    'reason' => $apt->reason,
                    'type' => $apt->type ?? 'presencial',
                    'joinable' => $apt->type === 'video'
                        && $apt->status === 'confirmed'
                        && AppointmentController::isJoinable($apt),
                    'status' => $apt->status,
                    'price' => $apt->price,
                ];
            });

        return response()->json($appointments);
    }

    /**
     * Staff updates the outcome of an appointment.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:completed,no_show,cancelled',
        ]);

        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        $appointment->update(['status' => $validated['status']]);

        if ($validated['status'] === 'cancelled') {
            app(FcmService::class)->notifyUser(
                $appointment->user_id,
                'Cita cancelada',
                'Tu cita fue cancelada por el equipo clínico. Puedes reagendar desde la app.',
                ['appointment_id' => $appointment->id, 'type' => 'appointment'],
            );
        }

        return response()->json(['success' => true, 'appointment' => $appointment->fresh()]);
    }

    /**
     * The in-portal video call page for a consultation.
     */
    public function callPage(string $id)
    {
        $appointment = Appointment::with(['professional', 'user'])->find($id);
        abort_unless($appointment && $appointment->type === 'video', 404);

        $patient = $appointment->user?->name ?? 'Paciente';
        if ($appointment->dependent_id) {
            $dependent = Dependent::find($appointment->dependent_id);
            if ($dependent) {
                $patient = $dependent->name;
            }
        }

        return view('doctor.call', [
            'appointment' => $appointment,
            'patientName' => $patient,
        ]);
    }

    /**
     * WebRTC session config for the staff side of a video call.
     */
    public function webrtcConfig(string $id): JsonResponse
    {
        $appointment = Appointment::find($id);
        if (!$appointment || $appointment->type !== 'video') {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        return response()->json([
            'role' => 'staff',
            'ice_servers' => app(WebRtcService::class)->iceServers(),
        ]);
    }

    /**
     * Push a WebRTC signal from the staff side. A new offer starts a fresh
     * session and clears every previous signal of the appointment.
     */
    public function postVideoSignal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:offer,answer,candidate,ready,hangup',
            'payload' => 'nullable',
        ]);

        $appointment = Appointment::find($id);
        if (!$appointment || $appointment->type !== 'video') {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        if ($validated['type'] === 'offer') {
            VideoSignal::where('appointment_id', $appointment->id)->delete();
        }

        $payload = $validated['payload'] ?? null;
        $signal = VideoSignal::create([
            'appointment_id' => $appointment->id,
            'sender' => 'staff',
            'type' => $validated['type'],
            'payload' => is_string($payload) ? $payload : json_encode($payload),
        ]);

        return response()->json(['id' => $signal->id], 201);
    }

    /**
     * Signals sent by the patient, newer than the given id.
     */
    public function videoSignals(Request $request, string $id): JsonResponse
    {
        $after = (int) $request->query('after', 0);

        $signals = VideoSignal::where('appointment_id', $id)
            ->where('sender', 'patient')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type,
                'payload' => $s->payload ? json_decode($s->payload, true) : null,
            ]);

        return response()->json(['signals' => $signals]);
    }

    /**
     * Weekly schedule blocks of a professional.
     */
    public function schedules(string $professionalId): JsonResponse
    {
        $blocks = ProfessionalSchedule::where('professional_id', $professionalId)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json($blocks);
    }

    /**
     * Add a weekly schedule block.
     */
    public function storeSchedule(Request $request, string $professionalId): JsonResponse
    {
        $validated = $request->validate([
            'day_of_week' => 'required|integer|between:1,7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        $block = ProfessionalSchedule::create([
            'professional_id' => $professionalId,
            'day_of_week' => $validated['day_of_week'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ]);

        return response()->json($block, 201);
    }

    /**
     * Remove a weekly schedule block.
     */
    public function destroySchedule(string $professionalId, int $blockId): JsonResponse
    {
        ProfessionalSchedule::where('professional_id', $professionalId)
            ->where('id', $blockId)
            ->delete();

        return response()->json(['success' => true]);
    }
}
