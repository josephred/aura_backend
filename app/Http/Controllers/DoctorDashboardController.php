<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\User;
use App\Models\Dependent;
use App\Models\ChatMessage;
use App\Models\PastService;
use App\Services\DispatchZoneService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DoctorDashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesStaffScope;

    /**
     * Display the doctor dashboard.
     */
    public function index()
    {
        return view('doctor.dashboard');
    }

    /**
     * Guardia visibility: professionals see unassigned requests (anyone
     * must be able to take them) plus the ones assigned to themselves;
     * admins see everything.
     */
    private function visibleBookings()
    {
        return ServiceRequest::query()->when(
            $this->scopedProfessionalId(),
            fn ($q, $pid) => $q->where(function ($sub) use ($pid) {
                $sub->whereNull('professional_id')->orWhere('professional_id', $pid);
            }),
        );
    }

    /**
     * Find a booking the logged-in staff member may operate on.
     */
    private function findVisibleBooking(string $id): ?ServiceRequest
    {
        return $this->visibleBookings()->where('id', $id)->first();
    }

    /**
     * Acting on an unassigned request claims it for the acting professional.
     * Admins coordinate but never take the visit themselves.
     */
    private function claimIfUnassigned(ServiceRequest $serviceRequest): void
    {
        $professionalId = $this->scopedProfessionalId();

        if (empty($serviceRequest->professional_id) && $professionalId && !$this->isAdmin()) {
            $serviceRequest->update(['professional_id' => $professionalId]);
        }
    }

    /**
     * A professional carrying a live visit is 'ocupado'; otherwise back to
     * 'disponible'. Never touches someone who logged off ('desconectado').
     */
    private function syncDutyStatus(?string $professionalId): void
    {
        if (!$professionalId) {
            return;
        }

        $professional = Professional::find($professionalId);
        if (!$professional || $professional->duty_status === 'desconectado') {
            return;
        }

        $busy = ServiceRequest::where('professional_id', $professionalId)
            ->whereIn('status', ['accepted', 'en_camino', 'en_atencion'])
            ->exists();

        $next = $busy ? 'ocupado' : 'disponible';
        if ($professional->duty_status !== $next) {
            $professional->update(['duty_status' => $next]);
        }
    }

    /**
     * Writes the clinical history entry for a completed visit.
     *
     * Records the professional who actually attended — taken from the request's
     * assignment, falling back to the staff member closing it. Previously this
     * picked a name at random from a hardcoded list, and wrote to columns that
     * do not exist in `past_services`, so completing a visit failed outright.
     */
    private function recordCompletedCare(ServiceRequest $serviceRequest): void
    {
        $service = ClinicalService::find($serviceRequest->service_id);
        $serviceTitle = $service ? $service->title : 'Atención Médica';

        $patientName = 'Usuario Principal';
        if ($serviceRequest->patient_type === 'dependent' && $serviceRequest->dependent_id) {
            $dependent = Dependent::find($serviceRequest->dependent_id);
            if ($dependent) {
                $patientName = "{$dependent->name} ({$dependent->relationship})";
            }
        }

        $professionalId = $serviceRequest->professional_id ?: $this->scopedProfessionalId();
        $professional = $professionalId ? Professional::find($professionalId) : null;

        $professionalName = $professional
            ? trim($professional->name . ($professional->specialty ? " ({$professional->specialty})" : ''))
            : ($this->staffDisplayName() ?: 'Personal Clínico de Aura');

        $months = [
            'January' => 'Enero', 'February' => 'Febrero', 'March' => 'Marzo',
            'April' => 'Abril', 'May' => 'Mayo', 'June' => 'Junio',
            'July' => 'Julio', 'August' => 'Agosto', 'September' => 'Septiembre',
            'October' => 'Octubre', 'November' => 'Noviembre', 'December' => 'Diciembre',
        ];
        $monthEn = date('F');
        $dateFormatted = date('d \d\e ') . ($months[$monthEn] ?? $monthEn) . date(' Y');

        PastService::create([
            'id' => 'past_' . time() . '_' . Str::random(4),
            'user_id' => $serviceRequest->user_id,
            'service_id' => $serviceRequest->service_id,
            'service_title' => $serviceTitle,
            'date' => $dateFormatted,
            'patient' => $patientName,
            'price' => $serviceRequest->final_price,
            'status' => 'completed',
            // Placeholder until the professional writes the clinical note from
            // the portal. It states what happened, it does not invent findings.
            'details' => "Atención de $serviceTitle realizada en domicilio. "
                . 'Resumen clínico pendiente de registro por el profesional.',
            // Snapshot of the display name, plus the durable link.
            'professional' => $professionalName,
            'professional_id' => $professionalId,
        ]);
    }

    /**
     * Get all active and pending bookings for the dashboard.
     */
    public function bookings(): JsonResponse
    {
        // Requests are dispatched per zone: the ones inside the professional's
        // coverage come first. The rest are still returned (flagged
        // `outside_zone`) so a request in an uncovered comuna is never orphaned.
        $scopedId = $this->scopedProfessionalId();
        $professional = $scopedId ? Professional::find($scopedId) : null;
        $dispatch = app(DispatchZoneService::class);

        $bookings = $this->visibleBookings()->orderBy('created_at', 'desc')->get()->map(function ($req) use ($professional, $dispatch) {
            $service = ClinicalService::find($req->service_id);
            $user = User::find($req->user_id);
            $dependent = $req->dependent_id ? Dependent::find($req->dependent_id) : null;

            $outsideZone = $professional !== null
                && !$dispatch->covers($professional, $req->zone);

            return [
                'outside_zone' => $outsideZone,
                'id' => $req->id,
                'user_id' => $req->user_id,
                'service_id' => $req->service_id,
                'professional_id' => $req->professional_id,
                'status' => $req->status,
                'patient_type' => $req->patient_type,
                'dependent_id' => $req->dependent_id,
                'address_text' => $req->address_text,
                'origin_address' => $req->origin_address,
                'destination_address' => $req->destination_address,
                'ambulance_type' => $req->ambulance_type,
                'symptoms_description' => $req->symptoms_description,
                // Authenticated media links, not raw storage paths.
                'symptom_audio_url' => $req->symptom_audio_link,
                'zone' => $req->zone,
                'prescription_name' => $req->prescription_name,
                'prescription_preview' => $req->prescription_preview,
                'prescription_file' => $req->prescription_url,
                'final_price' => $req->final_price,
                'start_time' => $req->start_time,
                'eta_minutes' => $req->eta_minutes,
                'current_step' => $req->current_step,
                'created_at' => $req->created_at ? $req->created_at->toIso8601String() : null,
                'service' => $service,
                'user' => $user,
                'dependent' => $dependent,
            ];
        })
        // Own zone first, then the rest — both keep their newest-first order.
        ->sortBy(fn ($booking) => $booking['outside_zone'] ? 1 : 0)
        ->values();

        return response()->json($bookings);
    }

    /**
     * Update the status of a specific booking.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:accepted,en_camino,en_atencion,completed,cancelled',
        ]);

        $serviceRequest = $this->findVisibleBooking($id);
        if (!$serviceRequest) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        // Acting on an unassigned request claims it for this professional
        $this->claimIfUnassigned($serviceRequest);

        $nextStatus = $validated['status'];
        $nextStep = 0;

        if ($nextStatus === 'accepted') {
            $nextStep = 1;
        } elseif ($nextStatus === 'en_camino') {
            $nextStep = 2;
        } elseif ($nextStatus === 'en_atencion') {
            $nextStep = 3;
        } elseif ($nextStatus === 'completed') {
            $nextStep = 4;
        }

        $serviceRequest->update([
            'status' => $nextStatus,
            'current_step' => $nextStep,
        ]);

        // Keep the duty status in sync with reality so the zone wait estimate
        // reflects actual capacity, not a flag someone forgot to flip.
        $this->syncDutyStatus($serviceRequest->professional_id);

        $timeStr = date('H:i');

        // Aviso de cada paso en el canal clínico.
        //
        // Iban sin `sender_name`, así que en el teléfono aparecían como un
        // «alguien» sin firma justo donde el resto de los mensajes del
        // profesional sí llevan nombre. Y no salía ninguna notificación: el
        // paciente solo se enteraba de que el profesional venía en camino si
        // tenía la app abierta en ese preciso momento.
        $staffName = $this->staffDisplayName();

        $stepMessages = [
            'accepted' => [
                'prefix' => 'web_msg_step1',
                'sender' => 'provider',
                'title' => 'Tu atención fue tomada',
                'text' => 'Hola, soy tu especialista clínico asignado. Ya estoy preparando el equipamiento para salir hacia tu dirección.',
            ],
            'en_camino' => [
                'prefix' => 'web_msg_step2',
                'sender' => 'provider',
                'title' => 'El profesional va en camino',
                'text' => 'He iniciado el trayecto hacia tu ubicación. Voy en camino directo.',
            ],
            'en_atencion' => [
                'prefix' => 'web_msg_step3',
                'sender' => 'provider',
                'title' => 'El profesional llegó',
                'text' => 'He llegado al domicilio. Estoy tocando el timbre para ingresar.',
            ],
            'completed' => [
                'prefix' => 'web_msg_step4',
                'sender' => 'system',
                'title' => 'Atención completada',
                'text' => 'Atención completada con éxito. Resumen médico disponible en el historial.',
            ],
        ];

        if (isset($stepMessages[$nextStatus])) {
            $step = $stepMessages[$nextStatus];

            ChatMessage::create([
                'id' => ChatMessage::nextId($step['prefix']),
                'service_request_id' => $id,
                'sender' => $step['sender'],
                // Los del sistema no se firman: no los escribió nadie.
                'sender_name' => $step['sender'] === 'provider' ? $staffName : null,
                'text' => $step['text'],
                'timestamp' => $timeStr,
            ]);

            app(\App\Services\FcmService::class)->notifyUser(
                $serviceRequest->user_id,
                $step['title'],
                $step['text'],
                ['booking_id' => $serviceRequest->id, 'type' => 'chat'],
            );
        }

        if ($nextStatus === 'completed') {
            $this->recordCompletedCare($serviceRequest);

            // El dinero entró a la plataforma; aquí queda anotado cuánto se
            // retuvo y cuánto se le debe al prestador. Es idempotente, así que
            // cerrar dos veces la atención no duplica el devengo.
            app(\App\Services\SettlementService::class)
                ->recordForServiceRequest($serviceRequest->fresh());
        }

        return response()->json([
            'success' => true,
            'booking' => $serviceRequest,
        ]);
    }

    /**
     * Receive the assigned professional's live GPS position and store it on the
     * booking so the patient app can track the approach in real time.
     */
    public function updateLocation(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $serviceRequest = $this->findVisibleBooking($id);
        if (!$serviceRequest) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        // Broadcasting a position also claims an unassigned request
        $this->claimIfUnassigned($serviceRequest);

        $serviceRequest->update([
            'professional_lat' => $validated['lat'],
            'professional_lng' => $validated['lng'],
            'professional_location_updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Get chat messages for a specific booking.
     */
    public function getMessages(string $id): JsonResponse
    {
        if (!$this->findVisibleBooking($id)) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        $messages = ChatMessage::where('service_request_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }

    /**
     * Send a chat message from the doctor/provider to the patient.
     */
    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:1000',
        ]);

        $serviceRequest = $this->findVisibleBooking($id);
        if (!$serviceRequest) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        // Replying to an unassigned request also claims it
        $this->claimIfUnassigned($serviceRequest);

        $message = ChatMessage::create([
            'id' => ChatMessage::nextId('web_msg'),
            'service_request_id' => $id,
            'sender' => 'provider',
            'sender_name' => $this->staffDisplayName(),
            'text' => $validated['text'],
            'timestamp' => date('H:i'),
        ]);

        $senderName = $this->staffDisplayName() ?: 'Equipo clínico';
        app(\App\Services\FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            "Mensaje de $senderName",
            $validated['text'],
            ['booking_id' => $serviceRequest->id, 'type' => 'chat'],
        );

        return response()->json($message, 201);
    }
}
