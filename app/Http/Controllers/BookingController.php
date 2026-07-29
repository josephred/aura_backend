<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ClinicalService;
use App\Models\ChatMessage;
use App\Models\PastService;
use App\Services\DispatchZoneService;
use App\Services\FcmService;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    /**
     * Get the active service request for the user.
     */
    public function active(): JsonResponse
    {
        // Eager-load so the serialized `assigned_professional` does not fire an
        // extra query, and so the app can show who is really attending.
        $active = ServiceRequest::with('professional')
            ->where('user_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->first();

        return response()->json($active);
    }

    /**
     * Store a newly created service request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|string|exists:clinical_services,id',
            'patient_type' => 'required|string|in:self,dependent',
            'dependent_id' => 'nullable|string|exists:dependents,id',
            'address_text' => 'required|string',
            'origin_address' => 'nullable|string',
            'destination_address' => 'nullable|string',
            'ambulance_type' => 'nullable|string',
            'patient_lat' => 'nullable|numeric',
            'patient_lng' => 'nullable|numeric',
            'symptoms_description' => 'nullable|string',
            'prescription_name' => 'nullable|string',
            'prescription_preview' => 'nullable|string',
            'prescription_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            // Voice note describing the symptoms (max ~2 min at 64 kbps ≈ 1 MB;
            // 5 MB leaves room for other encoders).
            'symptom_audio' => 'nullable|file|mimes:m4a,mp4,aac,mp3,wav,ogg,webm|max:5120',
            'exam_required' => 'nullable|string',
            'final_price' => 'required|integer',
            'eta_minutes' => 'required|integer',
        ]);

        // Fail closed BEFORE touching anything. Further down this method
        // cancels the patient's current request and creates a new row, so a
        // late failure would leave them with their previous request cancelled
        // and an unpayable one in its place.
        $mercadoPago = app(MercadoPagoService::class);
        if (!$mercadoPago->isConfigured() && app()->environment('production')) {
            return response()->json([
                'message' => 'El sistema de pagos no está disponible en este momento. '
                    . 'Tu solicitud no fue creada; inténtalo nuevamente en unos minutos.',
            ], 503);
        }

        // Clinical attachments are health data: they go to the private disk and
        // are served through /media/bookings/... which checks who is asking.
        $prescriptionPath = null;
        if ($request->hasFile('prescription_file')) {
            $prescriptionPath = $request->file('prescription_file')->store('prescriptions', 'local');
        }

        // Optional voice note recorded in the symptom descriptor.
        $symptomAudioPath = null;
        if ($request->hasFile('symptom_audio')) {
            $symptomAudioPath = $request->file('symptom_audio')->store('symptom-audio', 'local');
        }

        // Cancel any existing active request
        ServiceRequest::where('user_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'cancelled', 'current_step' => 0]);

        $timeStr = date('H:i');
        $requestId = 'req_' . time();

        // Requests are dispatched per zone (comuna), not to a hand-picked
        // doctor: we resolve the zone here and quote the wait that the zone's
        // current load implies.
        $zones = app(DispatchZoneService::class);
        $zone = $zones->resolveZoneFor(
            $validated['origin_address'] ?? $validated['address_text'],
            isset($validated['patient_lat']) ? (float) $validated['patient_lat'] : null,
            isset($validated['patient_lng']) ? (float) $validated['patient_lng'] : null,
        );
        $estimate = $zones->estimate($validated['service_id'], null, $zone);

        $serviceRequest = ServiceRequest::create([
            'id' => $requestId,
            'user_id' => auth()->id(),
            'service_id' => $validated['service_id'],
            'status' => 'pending_payment',
            'patient_type' => $validated['patient_type'],
            'dependent_id' => $validated['dependent_id'] ?? null,
            'address_text' => $validated['address_text'],
            'origin_address' => $validated['origin_address'] ?? null,
            'destination_address' => $validated['destination_address'] ?? null,
            'ambulance_type' => $validated['ambulance_type'] ?? null,
            'patient_lat' => $validated['patient_lat'] ?? null,
            'patient_lng' => $validated['patient_lng'] ?? null,
            'symptoms_description' => $validated['symptoms_description'] ?? null,
            // Stored as a private disk path; the model turns it into the
            // authenticated media URL when serialized.
            'symptom_audio_url' => $symptomAudioPath,
            'prescription_name' => $validated['prescription_name'] ?? null,
            'prescription_preview' => $validated['prescription_preview'] ?? null,
            'prescription_file' => $prescriptionPath,
            'exam_required' => $validated['exam_required'] ?? null,
            'payment_method' => 'mercadopago',
            'final_price' => $validated['final_price'],
            'start_time' => $timeStr,
            // Trust the live zone estimate over the static per-service ETA the
            // client sent, but never quote less than the client already saw.
            'eta_minutes' => max($validated['eta_minutes'], $estimate['eta_min_minutes']),
            'zone' => $zone,
            'queue_eta_minutes' => $estimate['eta_max_minutes'],
            'current_step' => 0,
        ]);

        $service = ClinicalService::find($validated['service_id']);
        $serviceTitle = $service ? $service->short_title : 'Servicio';

        if ($mercadoPago->isConfigured()) {
            $preference = $mercadoPago->createPreference($serviceRequest, $serviceTitle);
            if ($preference) {
                $serviceRequest->update([
                    'payment_preference_id' => $preference['id'],
                    'payment_url' => $preference['init_point'],
                    'payment_status' => 'pending',
                ]);
                return response()->json($serviceRequest->fresh(), 201);
            }

            // Configured but the gateway did not answer. Never hand out free
            // care in production: leave the request cancelled and let the
            // patient retry.
            if (app()->environment('production')) {
                $serviceRequest->update(['status' => 'cancelled', 'current_step' => 0]);

                return response()->json([
                    'message' => 'No pudimos generar el cobro. Tu solicitud fue anulada '
                        . 'y no se realizó ningún cargo. Inténtalo nuevamente.',
                ], 503);
            }
        }

        // Outside production only: no gateway configured, so activate the
        // booking directly to keep local development and tests workable.
        $this->activateBooking($serviceRequest, $serviceTitle);

        return response()->json($serviceRequest->fresh(), 201);
    }

    /**
     * Mark a booking as paid/accepted and open its clinical chat channel.
     */
    private function activateBooking(ServiceRequest $serviceRequest, ?string $serviceTitle = null): void
    {
        if ($serviceTitle === null) {
            $service = ClinicalService::find($serviceRequest->service_id);
            $serviceTitle = $service ? $service->short_title : 'Servicio';
        }

        $timeStr = date('H:i');

        $serviceRequest->update([
            'status' => 'accepted',
            'current_step' => 1,
        ]);

        $zoneLabel = $serviceRequest->zone && $serviceRequest->zone !== 'General'
            ? $serviceRequest->zone
            : 'tu zona';

        ChatMessage::create([
            'id' => 'm1_' . time(),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => "Canal clínico seguro iniciado para: $serviceTitle. "
                . "Tu solicitud está en la cola de $zoneLabel y se asignará al próximo profesional en turno del sector.",
            'timestamp' => $timeStr,
        ]);

        ChatMessage::create([
            'id' => 'm2_' . time(),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'provider',
            'text' => "Hola, soy el especialista asignado para tu atención de $serviceTitle. Ya estoy coordinando los insumos médicos necesarios y me dirijo hacia tu ubicación. ¿Hay algún detalle adicional que deba saber del paciente?",
            'timestamp' => $timeStr,
        ]);

        app(FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            'Atención confirmada',
            "Tu solicitud de $serviceTitle fue confirmada. El especialista ya está en coordinación.",
            ['booking_id' => $serviceRequest->id, 'status' => 'accepted'],
        );
    }

    /**
     * Approve a booking after its payment is confirmed. Idempotent.
     */
    public function approvePayment(ServiceRequest $serviceRequest, string $paymentId): void
    {
        if ($serviceRequest->payment_status === 'approved') {
            return;
        }

        $serviceRequest->update([
            'payment_status' => 'approved',
            'payment_id' => $paymentId,
        ]);

        if ($serviceRequest->status === 'pending_payment') {
            $this->activateBooking($serviceRequest);
        }
    }

    /**
     * Check (and refresh from Mercado Pago) the payment status of a booking.
     */
    public function paymentStatus(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::where('user_id', auth()->id())->where('id', $id)->first();

        if (!$serviceRequest) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        if ($serviceRequest->status === 'pending_payment') {
            $mercadoPago = app(MercadoPagoService::class);
            if ($mercadoPago->isConfigured()) {
                $payment = $mercadoPago->findApprovedPayment($serviceRequest->id);
                if ($payment) {
                    $this->approvePayment($serviceRequest, (string) $payment['id']);
                }
            }
        }

        return response()->json($serviceRequest->fresh());
    }

    /**
     * Cancel the active request.
     */
    public function cancel(string $id): JsonResponse
    {
        $request = ServiceRequest::where('user_id', auth()->id())->where('id', $id)->first();

        if (!$request) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        $request->update([
            'status' => 'cancelled',
            'current_step' => 0,
        ]);

        return response()->json($request);
    }

    /**
     * Get the history of completed services.
     */
    public function history(): JsonResponse
    {
        $history = PastService::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($history);
    }

    /**
     * Stream status updates for a specific booking request using Server-Sent Events (SSE).
     */
    public function streamStatus($id)
    {
        return response()->stream(function () use ($id) {
            $lastStatus = null;
            $lastStep = null;
            $lastMessageCount = null;
            $lastLocation = null;

            // Loop for up to 50 seconds to avoid exceeding webserver timeout limits
            $elapsed = 0;
            while ($elapsed < 50) {
                // `with` keeps the appended assigned_professional from firing an
                // extra query on every one-second tick of the stream.
                $serviceRequest = \App\Models\ServiceRequest::with('professional')->find($id);

                if (!$serviceRequest) {
                    echo "data: " . json_encode(['error' => 'Not found']) . "\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                $messages = \App\Models\ChatMessage::where('service_request_id', $id)
                    ->orderBy('created_at', 'asc')
                    ->get();
                $messageCount = $messages->count();

                // Track the professional's live position so movement alone
                // (without a status/step change) still pushes an SSE update.
                $location = $serviceRequest->professional_lat . ',' . $serviceRequest->professional_lng;

                if ($serviceRequest->status !== $lastStatus || $serviceRequest->current_step !== $lastStep || $messageCount !== $lastMessageCount || $location !== $lastLocation) {
                    $lastStatus = $serviceRequest->status;
                    $lastStep = $serviceRequest->current_step;
                    $lastMessageCount = $messageCount;
                    $lastLocation = $location;

                    // Send payload
                    echo "data: " . json_encode([
                        'booking' => $serviceRequest,
                        'message_count' => $messageCount,
                        'messages' => $messages,
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                }

                sleep(1);
                $elapsed += 1;
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable buffering for Nginx
        ]);
    }
}
