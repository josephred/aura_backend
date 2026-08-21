<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ClinicalService;
use App\Models\ChatMessage;
use App\Models\PastService;
use App\Services\DispatchZoneService;
use App\Services\FcmService;
use App\Rules\AtLeastTwoSymptoms;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /** Services whose form collects symptoms and therefore requires them. */
    private const SERVICES_REQUIRING_SYMPTOMS = ['medico'];

    /**
     * Get the active service request for the user.
     */
    public function active(): JsonResponse
    {
        // Eager-load so the serialized `assigned_professional` does not fire an
        // extra query, and so the app can show who is really attending.
        //
        // A scheduled lab collection is not "the active request" while it is
        // still days away: it must not take over the tracking screen, and — more
        // importantly — it must not be cancelled by `store()` the moment the
        // patient asks for a doctor today. It joins the active channel only
        // once its slot is imminent or the laboratorista is already moving.
        $active = ServiceRequest::with('professional')
            ->where('user_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(function ($query) {
                $query->where('is_scheduled', false)
                    ->orWhereIn('status', ['en_camino', 'en_atencion'])
                    ->orWhere('scheduled_at', '<=', now()->addMinutes(
                        (int) config('aura.lab.activation_window_minutes'),
                    ));
            })
            // Las inmediatas primero, las agendadas despues.
            //
            // Esto era `orderByRaw('CASE WHEN is_scheduled = 1 ...')`, y en
            // Postgres —que es lo que corre en produccion, porque config
            // /database.php cae al default 'pgsql' y el Dockerfile borra
            // DB_CONNECTION del .env— comparar un boolean con un entero es un
            // error de tipos: "operator does not exist: boolean = integer".
            // Este endpoint devolvia 500 siempre, y la app, que solo acepta un
            // 200, lo interpretaba como "el paciente no tiene atencion activa":
            // pantalla de chat en blanco y seguimiento vacio con la solicitud
            // abierta. Los tests no lo veian porque corren en SQLite, donde
            // `boolean = 1` es valido.
            ->orderBy('is_scheduled')
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
            'destination_address' => in_array($request->input('service_id'), ['ambulancia', 'traslado_simple', 'traslado_avanzado'], true)
                ? 'required|string'
                : 'nullable|string',
            'ambulance_type' => 'nullable|string',
            'patient_lat' => 'nullable|numeric',
            'patient_lng' => 'nullable|numeric',
            'destination_lat' => 'nullable|numeric',
            'destination_lng' => 'nullable|numeric',
            // The clinical history is opened from this text, so services that
            // ask for symptoms require at least two of them.
            'symptoms_description' => in_array($request->input('service_id'), self::SERVICES_REQUIRING_SYMPTOMS, true)
                ? ['required', 'string', 'max:1000', new AtLeastTwoSymptoms()]
                : ['nullable', 'string', 'max:1000'],
            'prescription_name' => 'nullable|string',
            'prescription_preview' => 'nullable|string',
            'prescription_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            // Voice note describing the symptoms (max ~2 min at 64 kbps ≈ 1 MB;
            // 5 MB leaves room for other encoders).
            'symptom_audio' => 'nullable|file|mimes:m4a,mp4,aac,mp3,wav,ogg,webm|max:5120',
            'exam_required' => 'nullable|string',
            // Se sigue aceptando por compatibilidad con la app instalada, pero
            // ya no decide nada: el importe se calcula abajo desde el catalogo.
            'final_price' => 'nullable|integer|min:0',
            'eta_minutes' => 'required|integer',
        ]);

        // D.3 — El dependiente debe pertenecer al usuario autenticado
        if ($validated['patient_type'] === 'dependent' && !empty($validated['dependent_id'])) {
            $ownsDependent = \App\Models\Dependent::where('id', $validated['dependent_id'])
                ->where('user_id', auth()->id())
                ->exists();
            if (!$ownsDependent) {
                return response()->json(['error' => 'El dependiente seleccionado no pertenece a tu cuenta'], 403);
            }
        }

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

        // Cancel any existing active request.
        //
        // Scheduled work is deliberately excluded: a toma de muestras booked
        // for Thursday is a separate commitment with a reserved slot, and
        // silently cancelling it because the patient needed a doctor today
        // would free the slot without anyone being told.
        ServiceRequest::where('user_id', auth()->id())
            ->where('is_scheduled', false)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'cancelled', 'current_step' => 0]);

        $timeStr = date('H:i');
        // Mismo problema que en los ids de chat: `time()` solo tiene resolución
        // de un segundo, así que dos pacientes que confirman a la vez chocaban
        // contra la clave primaria.
        $requestId = 'req_' . now()->timestamp . '_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));

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

        // Cálculo de distancia y tarifa georreferenciada para traslados (REQ-11)
        $distanceKm = null;
        $transportFee = null;
        if (
            isset($validated['patient_lat'], $validated['patient_lng'], $validated['destination_lat'], $validated['destination_lng'])
        ) {
            $distanceKm = $zones->calculateDistanceKm(
                (float) $validated['patient_lat'],
                (float) $validated['patient_lng'],
                (float) $validated['destination_lat'],
                (float) $validated['destination_lng']
            );
            $transportFee = $zones->calculateTransportFee($distanceKm, $validated['ambulance_type'] ?? null);
        }

        // El importe lo fija el servidor.
        $service = ClinicalService::find($validated['service_id']);
        $finalPrice = $this->resolvePrice($service, $validated['ambulance_type'] ?? null, $distanceKm);

        if (isset($validated['final_price']) && (int) $validated['final_price'] !== $finalPrice) {
            Log::warning('El precio enviado por el cliente no coincide con el servidor', [
                'service_id' => $validated['service_id'],
                'ambulance_type' => $validated['ambulance_type'] ?? null,
                'client_price' => (int) $validated['final_price'],
                'server_price' => $finalPrice,
                'user_id' => auth()->id(),
            ]);
        }

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
            'destination_lat' => $validated['destination_lat'] ?? null,
            'destination_lng' => $validated['destination_lng'] ?? null,
            'distance_km' => $distanceKm,
            'transport_fee' => $transportFee,
            'symptoms_description' => $validated['symptoms_description'] ?? null,
            // Stored as a private disk path; the model turns it into the
            // authenticated media URL when serialized.
            'symptom_audio_url' => $symptomAudioPath,
            'prescription_name' => $validated['prescription_name'] ?? null,
            'prescription_preview' => $validated['prescription_preview'] ?? null,
            'prescription_file' => $prescriptionPath,
            'exam_required' => $validated['exam_required'] ?? null,
            'payment_method' => 'mercadopago',
            'final_price' => $finalPrice,
            'start_time' => $timeStr,
            // Trust the live zone estimate over the static per-service ETA the
            // client sent, but never quote less than the client already saw.
            'eta_minutes' => max($validated['eta_minutes'], $estimate['eta_min_minutes']),
            'zone' => $zone,
            'queue_eta_minutes' => $estimate['eta_max_minutes'],
            'current_step' => 0,
        ]);

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
     * Cotización previa de traslado por georreferenciación (REQ-11).
     */
    public function quoteTransport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => 'required|numeric',
            'origin_lng' => 'required|numeric',
            'destination_lat' => 'required|numeric',
            'destination_lng' => 'required|numeric',
            'ambulance_type' => 'nullable|string',
        ]);

        $zones = app(DispatchZoneService::class);
        $distanceKm = $zones->calculateDistanceKm(
            (float) $validated['origin_lat'],
            (float) $validated['origin_lng'],
            (float) $validated['destination_lat'],
            (float) $validated['destination_lng']
        );

        $ambulanceType = $validated['ambulance_type'] ?? 'basic';
        $isMedicalized = ($ambulanceType === 'medicalized' || $ambulanceType === 'avanzada');
        $baseFee = (int) config(
            $isMedicalized ? 'aura.transport.base_fee_medicalized' : 'aura.transport.base_fee_basic',
            $isMedicalized ? 28500 : 18500
        );
        $pricePerKm = (int) config('aura.transport.price_per_km', 1200);
        $transportFee = $zones->calculateTransportFee($distanceKm, $ambulanceType);

        $surcharge = intdiv($transportFee * (int) config('aura.patient_surcharge_bps', 1500), 10000);
        $finalPrice = $transportFee + $surcharge;

        return response()->json([
            'distance_km' => $distanceKm,
            'base_fee' => $baseFee,
            'price_per_km' => $pricePerKm,
            'transport_fee' => $transportFee,
            'surcharge' => $surcharge,
            'final_price' => $finalPrice,
            'ambulance_type' => $ambulanceType,
        ]);
    }

    /**
     * Importe a cobrar por una prestacion, calculado desde el catalogo o georreferenciación.
     *
     * Todo en enteros y en puntos base, como el resto del dinero del proyecto,
     * para que lo cobrado, lo retenido y lo dispersado cuadren sin flotantes.
     */
    private function resolvePrice(?ClinicalService $service, ?string $ambulanceType, ?float $distanceKm = null): int
    {
        $base = (int) ($service->base_price ?? 0);

        if ($service !== null && in_array($service->id, ['ambulancia', 'traslado_simple', 'traslado_avanzado'], true)) {
            if ($distanceKm !== null) {
                $base = app(DispatchZoneService::class)->calculateTransportFee($distanceKm, $ambulanceType);
            } elseif ($ambulanceType === 'medicalized' || $ambulanceType === 'avanzada') {
                $base = (int) config('aura.transport.base_fee_medicalized', 28500);
            } else {
                $base = (int) config('aura.transport.base_fee_basic', 18500);
            }
        }

        $surcharge = intdiv($base * (int) config('aura.patient_surcharge_bps', 1500), 10000);
        $total = $base + $surcharge;

        // REQ-13 — Descuentos por suscripción activa
        $targetUserId = $userId ?? auth()->id();
        if ($targetUserId) {
            $subscription = \App\Models\UserSubscription::with('plan')
                ->where('user_id', $targetUserId)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();

            if ($subscription && $subscription->plan) {
                // Si incluye consultas y quedan cupos disponibles
                if ($subscription->hasRemainingConsultations() && in_array($service?->id, ['medico', 'enfermeria', 'telemedicina', 'kinesiologia'], true)) {
                    return 0;
                }

                // Descuento porcentual del plan
                $discountPercent = $subscription->plan->discount_percentage ?? 0;
                if ($discountPercent > 0) {
                    $discountAmount = intdiv($total * $discountPercent, 100);
                    $total = max(0, $total - $discountAmount);
                }
            }
        }

        return $total;
    }

    /**
     * Mark a booking as paid/accepted and open its clinical chat channel.
     *
     * Public because the lab flow lives in its own controller but must confirm
     * bookings through exactly this path — two copies of "what happens when a
     * request is paid" is how the chat channel ends up missing on one of them.
     */
    public function activateBooking(ServiceRequest $serviceRequest, ?string $serviceTitle = null): void
    {
        if ($serviceRequest->is_scheduled) {
            $this->activateScheduledBooking($serviceRequest);

            return;
        }

        // Registrar uso de consulta bonificada en la suscripción
        if ((int) $serviceRequest->final_price === 0) {
            $subscription = \App\Models\UserSubscription::where('user_id', $serviceRequest->user_id)
                ->where('status', 'active')
                ->first();
            if ($subscription) {
                $subscription->increment('consultations_used');
            }
        }

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
            'id' => ChatMessage::nextId('m1'),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => "Canal clínico seguro iniciado para: $serviceTitle. "
                . "Tu solicitud está en la cola de $zoneLabel y se asignará al próximo profesional en turno del sector.",
            'timestamp' => $timeStr,
        ]);

        ChatMessage::create([
            'id' => ChatMessage::nextId('m2'),
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
     * Confirm a booking that was reserved into a published slot.
     *
     * It lands on `scheduled`, not `accepted`: nobody is on their way yet, and
     * telling the patient "voy en camino" days before the collection would be
     * both wrong and alarming. The messages state what actually happens —
     * the slot is reserved and the indications reached the laboratorista.
     */
    private function activateScheduledBooking(ServiceRequest $serviceRequest): void
    {
        $serviceRequest->update([
            'status' => 'scheduled',
            'current_step' => 1,
        ]);

        $when = $this->formatScheduleEs($serviceRequest->scheduled_at);
        $timeStr = date('H:i');

        ChatMessage::create([
            'id' => ChatMessage::nextId('m1'),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => "Toma de muestras agendada para el $when en {$serviceRequest->address_text}. "
                . 'Este canal queda abierto para coordinar cualquier cambio.',
            'timestamp' => $timeStr,
        ]);

        if (!empty($serviceRequest->clinical_notes)) {
            ChatMessage::create([
                'id' => ChatMessage::nextId('m2'),
                'service_request_id' => $serviceRequest->id,
                'sender' => 'system',
                'text' => 'Indicaciones registradas para el laboratorista: '
                    . $serviceRequest->clinical_notes,
                'timestamp' => $timeStr,
            ]);
        }

        app(FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            'Toma de muestras agendada',
            "Tu toma de muestras quedó agendada para el $when.",
            ['booking_id' => $serviceRequest->id, 'status' => 'scheduled'],
        );
    }

    /**
     * Human date in Spanish for a scheduled collection.
     */
    private function formatScheduleEs($scheduledAt): string
    {
        if (empty($scheduledAt)) {
            return 'la fecha acordada';
        }

        $date = $scheduledAt instanceof \Carbon\Carbon
            ? $scheduledAt
            : \Carbon\Carbon::parse($scheduledAt);

        $days = ['Monday' => 'lunes', 'Tuesday' => 'martes', 'Wednesday' => 'miércoles',
            'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sábado', 'Sunday' => 'domingo'];
        $months = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril',
            'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto',
            'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];

        $day = $days[$date->format('l')] ?? $date->format('l');
        $month = $months[$date->format('F')] ?? $date->format('F');

        return "$day {$date->day} de $month a las {$date->format('H:i')}";
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
        // Por este stream viaja el hilo completo del canal clinico. Se abria
        // con `ServiceRequest::find($id)` a secas: cualquier usuario
        // autenticado que conociera —o adivinara— el id de otra reserva leia
        // su seguimiento, su direccion y toda la conversacion con el
        // profesional. La reserva se resuelve contra el dueno de la sesion, y
        // el bucle vuelve a consultarla siempre acotada a ese usuario.
        $userId = auth()->id();

        $owns = ServiceRequest::where('user_id', $userId)->where('id', $id)->exists();
        if (!$owns) {
            return response()->json(['error' => 'Service request not found'], 404);
        }

        // Un 204 le dice a la app "aquí no hay stream" sin que se quede
        // reintentando: ella sigue con su propio refresco del hilo.
        if (!config('aura.sse.enabled')) {
            return response()->noContent();
        }

        $maxSeconds = max(1, (int) config('aura.sse.max_seconds'));

        return response()->stream(function () use ($id, $userId, $maxSeconds) {
            $lastStatus = null;
            $lastStep = null;
            $lastMessageCount = null;
            $lastLocation = null;

            // Se corta antes del timeout del servidor web; el cliente reabre.
            $elapsed = 0;
            while ($elapsed < $maxSeconds) {
                // `with` keeps the appended assigned_professional from firing an
                // extra query on every one-second tick of the stream.
                $serviceRequest = \App\Models\ServiceRequest::with('professional')
                    ->where('user_id', $userId)
                    ->find($id);

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

                // Cerrada o anulada la atencion ya no hay nada que emitir.
                // Mantener el proceso ocupado los 50 s completos solo le quita
                // un worker a la siguiente peticion — incluida la del propio
                // paciente pidiendo el chat.
                if (in_array($serviceRequest->status, ['completed', 'cancelled'], true)) {
                    break;
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
