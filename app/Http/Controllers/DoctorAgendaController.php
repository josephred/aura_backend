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
use Illuminate\Support\Facades\Log;

class DoctorAgendaController extends Controller
{
    /**
     * Display the agenda page.
     */
    public function index()
    {
        return view('doctor.agenda', [
            'staffName' => session('staff_name', 'Equipo Aura'),
            'staffRole' => session('staff_role', 'professional'),
            'staffProfessionalId' => session('staff_professional_id'),
        ]);
    }

    use \App\Http\Controllers\Concerns\ResolvesStaffScope;

    /**
     * Upcoming and recent appointments for the staff portal. Professionals
     * only see their own agenda; admins see everything.
     */
    public function appointments(): JsonResponse
    {
        $appointments = Appointment::with(['professional', 'user'])
            ->where('scheduled_at', '>=', now()->subDays(7))
            ->when($this->scopedProfessionalId(), fn ($q, $id) => $q->where('professional_id', $id))
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

        $appointment = Appointment::when(
                $this->scopedProfessionalId(),
                fn ($q, $pid) => $q->where('professional_id', $pid),
            )->find($id);
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
        $appointment = Appointment::with(['professional', 'user'])
            ->when($this->scopedProfessionalId(), fn ($q, $pid) => $q->where('professional_id', $pid))
            ->find($id);
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
        $appointment = Appointment::when(
                $this->scopedProfessionalId(),
                fn ($q, $pid) => $q->where('professional_id', $pid),
            )->find($id);
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
     * session and clears previous STAFF signals only — patient signals
     * (answer, candidates) are preserved to avoid a race condition.
     */
    public function postVideoSignal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:offer,answer,candidate,ready,hangup',
            'payload' => 'nullable',
        ]);

        $appointment = Appointment::when(
                $this->scopedProfessionalId(),
                fn ($q, $pid) => $q->where('professional_id', $pid),
            )->find($id);
        if (!$appointment || $appointment->type !== 'video') {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

        // Only wipe previous staff signals (old offer + old candidates).
        // Patient signals are kept so we don't lose an answer that crossed
        // the wire at the same time the doctor re-sent an offer.
        if ($validated['type'] === 'offer') {
            VideoSignal::where('appointment_id', $appointment->id)
                ->where('sender', 'staff')
                ->delete();
        }

        $payload = $validated['payload'] ?? null;
        $signal = VideoSignal::create([
            'appointment_id' => $appointment->id,
            'sender' => 'staff',
            'type' => $validated['type'],
            'payload' => is_string($payload) ? $payload : json_encode($payload),
        ]);

        Log::info('VIDEO-SIGNAL staff', ['id' => $signal->id, 'apt' => $id, 'type' => $validated['type']]);

        return response()->json(['id' => $signal->id], 201);
    }

    /**
     * Portal accounts overview (admin only).
     */
    public function accounts(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['error' => 'Solo administradores'], 403);
        }

        $accounts = \App\Models\Professional::orderBy('name')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'specialty' => $p->specialty,
            'email' => $p->email,
            'role' => $p->role ?? 'professional',
            'has_password' => !empty($p->password),
            'last_login_at' => $p->last_login_at?->toIso8601String(),
        ]);

        return response()->json($accounts);
    }

    /**
     * Create or update a professional's portal account (admin only).
     * Returns the generated password when none is provided.
     */
    public function saveAccount(Request $request, string $professionalId): JsonResponse
    {
        if (!$this->isAdmin()) {
            return response()->json(['error' => 'Solo administradores'], 403);
        }

        $professional = \App\Models\Professional::find($professionalId);
        if (!$professional) {
            return response()->json(['error' => 'Profesional no encontrado'], 404);
        }

        $validated = $request->validate([
            'email' => 'required|email|unique:professionals,email,' . $professionalId,
            'password' => 'nullable|string|min:8',
        ]);

        $generated = null;
        $password = $validated['password'] ?? null;
        if (empty($password)) {
            $generated = \Illuminate\Support\Str::random(12);
            $password = $generated;
        }

        $professional->update([
            'email' => strtolower($validated['email']),
            'password' => \Illuminate\Support\Facades\Hash::make($password),
        ]);

        return response()->json([
            'success' => true,
            'generated_password' => $generated,
        ]);
    }

    /**
     * Signals sent by the patient, newer than the given id.
     */
    public function videoSignals(Request $request, string $id): JsonResponse
    {
        $scoped = $this->scopedProfessionalId();
        if ($scoped !== null && !Appointment::where('id', $id)->where('professional_id', $scoped)->exists()) {
            return response()->json(['error' => 'Cita no encontrada'], 404);
        }

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
     * Professionals may only manage their own weekly schedule.
     */
    private function deniesScheduleAccess(string $professionalId): bool
    {
        $scoped = $this->scopedProfessionalId();

        return $scoped !== null && $scoped !== $professionalId;
    }

    /**
     * Weekly schedule blocks of a professional.
     */
    public function schedules(string $professionalId): JsonResponse
    {
        if ($this->deniesScheduleAccess($professionalId)) {
            return response()->json(['error' => 'Sin permiso sobre este profesional'], 403);
        }
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
        if ($this->deniesScheduleAccess($professionalId)) {
            return response()->json(['error' => 'Sin permiso sobre este profesional'], 403);
        }

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
        if ($this->deniesScheduleAccess($professionalId)) {
            return response()->json(['error' => 'Sin permiso sobre este profesional'], 403);
        }

        ProfessionalSchedule::where('professional_id', $professionalId)
            ->where('id', $blockId)
            ->delete();

        return response()->json(['success' => true]);
    }
}
