<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Dependent;
use App\Models\ProfessionalSchedule;
use App\Services\FcmService;
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
