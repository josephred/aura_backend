<?php

namespace App\Http\Controllers;

use App\Models\ClinicalService;
use App\Models\LabResult;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Services\DispatchZoneService;
use App\Services\LabSchedulingService;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Módulo E — flujo de laboratorio del paciente.
 *
 * A diferencia de una atención médica, una toma de muestras no se despacha
 * como urgencia: el paciente elige un cupo que el laboratorista publicó de
 * antemano. Por eso vive en su propio controlador y no en `BookingController`,
 * que reparte por zona al primer profesional en turno.
 */
class LabController extends Controller
{
    /** Servicio del catálogo que representa la toma de muestras. */
    public const SERVICE_ID = 'laboratorio';

    /**
     * Cupos disponibles para una fecha.
     */
    public function slots(Request $request, LabSchedulingService $scheduling): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'zone' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'date' => $validated['date'],
            'slots' => $scheduling->slotsForDate($validated['date'], $validated['zone'] ?? null),
        ]);
    }

    /**
     * Próximas fechas con al menos un cupo libre, para pintar el calendario de
     * una sola llamada en vez de una por día.
     */
    public function availability(Request $request, LabSchedulingService $scheduling): JsonResponse
    {
        $validated = $request->validate([
            'zone' => 'nullable|string|max:120',
            'days' => 'nullable|integer|between:1,60',
        ]);

        return response()->json([
            'dates' => $scheduling->datesWithAvailability(
                $validated['zone'] ?? null,
                $validated['days'] ?? 14,
            ),
        ]);
    }

    /**
     * Agenda una toma de muestras en un cupo publicado.
     */
    public function store(Request $request, LabSchedulingService $scheduling): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => 'required|integer|exists:lab_schedules,id',
            'starts_at' => 'required|date',
            'patient_type' => 'required|string|in:self,dependent',
            'dependent_id' => 'nullable|string|exists:dependents,id',
            'address_text' => 'required|string|max:255',
            'patient_lat' => 'nullable|numeric|between:-90,90',
            'patient_lng' => 'nullable|numeric|between:-180,180',
            // Qué exámenes se piden. Es lo que el laboratorio necesita para
            // preparar tubos y conservación, así que es obligatorio.
            'exam_required' => 'required|string|max:1000',
            // E.2 — condiciones previas e indicaciones para el laboratorista.
            'clinical_notes' => 'nullable|string|max:1000',
            'prescription_name' => 'nullable|string|max:255',
            'prescription_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        // D.3 — El dependiente debe pertenecer al usuario autenticado
        if ($validated['patient_type'] === 'dependent' && !empty($validated['dependent_id'])) {
            $ownsDependent = Dependent::where('id', $validated['dependent_id'])
                ->where('user_id', auth()->id())
                ->exists();
            if (!$ownsDependent) {
                return response()->json(['error' => 'El dependiente seleccionado no pertenece a tu cuenta'], 403);
            }
        }

        $service = ClinicalService::find(self::SERVICE_ID);
        if ($service === null) {
            return response()->json(['error' => 'El servicio de laboratorio no está disponible.'], 503);
        }

        // La hora llega como instante absoluto desde la app y como texto local
        // desde los tests; normalizar a la zona horaria de la aplicación hace
        // que ambos apunten al mismo cupo de pared.
        $startsAt = Carbon::parse($validated['starts_at'])
            ->setTimezone(config('app.timezone'))
            ->seconds(0);

        $block = $scheduling->findOpenSlot((int) $validated['schedule_id'], $startsAt);
        if ($block === null) {
            return response()->json([
                'error' => 'Ese horario ya no está disponible. Elige otro bloque.',
            ], 409);
        }

        $mercadoPago = app(MercadoPagoService::class);
        if (!$mercadoPago->isConfigured() && app()->environment('production')) {
            return response()->json([
                'message' => 'El sistema de pagos no está disponible en este momento. '
                    . 'Tu solicitud no fue creada; inténtalo nuevamente en unos minutos.',
            ], 503);
        }

        // Los adjuntos clínicos van al disco privado y se sirven por
        // /media/bookings/... con autorización, igual que en el flujo médico.
        $prescriptionPath = null;
        if ($request->hasFile('prescription_file')) {
            $prescriptionPath = $request->file('prescription_file')->store('prescriptions', 'local');
        }

        $zone = app(DispatchZoneService::class)->resolveZoneFor(
            $validated['address_text'],
            isset($validated['patient_lat']) ? (float) $validated['patient_lat'] : null,
            isset($validated['patient_lng']) ? (float) $validated['patient_lng'] : null,
        );

        // El precio lo fija el catálogo, no el cliente. En el flujo médico
        // heredado el importe viaja desde la app; aquí no, porque no hay
        // ninguna razón para que el monto a cobrar dependa del dispositivo.
        $price = (int) $service->base_price;

        $serviceRequest = DB::transaction(function () use (
            $scheduling, $block, $startsAt, $validated, $prescriptionPath, $zone, $price
        ) {
            // Recuento con bloqueo: cierra la ventana entre la comprobación de
            // arriba y esta escritura, en la que dos pacientes pueden pedir el
            // último cupo a la vez.
            if ($scheduling->remainingFor($block, $startsAt, lock: true) <= 0) {
                return null;
            }

            return ServiceRequest::create([
                'id' => 'lab_' . now()->timestamp . '_' . Str::lower(Str::random(4)),
                'user_id' => auth()->id(),
                'service_id' => self::SERVICE_ID,
                'status' => 'pending_payment',
                'patient_type' => $validated['patient_type'],
                'dependent_id' => $validated['dependent_id'] ?? null,
                'address_text' => $validated['address_text'],
                'patient_lat' => $validated['patient_lat'] ?? null,
                'patient_lng' => $validated['patient_lng'] ?? null,
                'exam_required' => $validated['exam_required'],
                'clinical_notes' => $validated['clinical_notes'] ?? null,
                'prescription_name' => $validated['prescription_name'] ?? null,
                'prescription_file' => $prescriptionPath,
                'payment_method' => 'mercadopago',
                'final_price' => $price,
                'start_time' => $startsAt->format('H:i'),
                'eta_minutes' => 0,
                'current_step' => 0,
                'zone' => $zone,
                // La toma queda asignada de entrada a quien publicó el bloque:
                // el paciente sabe desde ya quién lo va a visitar.
                'professional_id' => $block->professional_id,
                'lab_schedule_id' => $block->id,
                'scheduled_at' => $startsAt,
                'is_scheduled' => true,
            ]);
        });

        if ($serviceRequest === null) {
            return response()->json([
                'error' => 'Ese horario acaba de ser tomado. Elige otro bloque.',
            ], 409);
        }

        if ($mercadoPago->isConfigured()) {
            $preference = $mercadoPago->createPreference($serviceRequest, 'Toma de muestras a domicilio');
            if ($preference) {
                $serviceRequest->update([
                    'payment_preference_id' => $preference['id'],
                    'payment_url' => $preference['init_point'],
                    'payment_status' => 'pending',
                ]);

                return response()->json($this->present($serviceRequest->fresh()), 201);
            }

            if (app()->environment('production')) {
                // Anular libera el cupo: `OCCUPYING_STATUSES` no incluye
                // 'cancelled', así que vuelve a ofrecerse de inmediato.
                $serviceRequest->update(['status' => 'cancelled', 'current_step' => 0]);

                return response()->json([
                    'message' => 'No pudimos generar el cobro. Tu solicitud fue anulada '
                        . 'y no se realizó ningún cargo. Inténtalo nuevamente.',
                ], 503);
            }
        }

        // Fuera de producción, sin pasarela: confirmar la agenda directamente
        // para que desarrollo y tests sigan siendo usables.
        app(BookingController::class)->activateBooking($serviceRequest);

        return response()->json($this->present($serviceRequest->fresh()), 201);
    }

    /**
     * Tomas de muestra del paciente, la próxima primero.
     */
    public function index(): JsonResponse
    {
        $requests = ServiceRequest::with('professional')
            ->where('user_id', auth()->id())
            ->where('is_scheduled', true)
            ->where('service_id', self::SERVICE_ID)
            ->orderByDesc('scheduled_at')
            ->limit(50)
            ->get()
            ->map(fn (ServiceRequest $req) => $this->present($req));

        return response()->json($requests);
    }

    /**
     * Cancela una toma futura y libera el cupo.
     */
    public function cancel(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::where('user_id', auth()->id())
            ->where('is_scheduled', true)
            ->find($id);

        if ($serviceRequest === null) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        }

        if (!in_array($serviceRequest->status, ['pending_payment', 'scheduled', 'accepted'], true)) {
            return response()->json(['error' => 'La toma de muestras ya no se puede cancelar'], 422);
        }

        $serviceRequest->update(['status' => 'cancelled', 'current_step' => 0]);

        return response()->json($this->present($serviceRequest->fresh()));
    }

    /**
     * Consulta (y refresca contra Mercado Pago) el estado de pago.
     */
    public function paymentStatus(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::where('user_id', auth()->id())
            ->where('is_scheduled', true)
            ->find($id);

        if ($serviceRequest === null) {
            return response()->json(['error' => 'Solicitud no encontrada'], 404);
        }

        if ($serviceRequest->status === 'pending_payment') {
            $mercadoPago = app(MercadoPagoService::class);
            if ($mercadoPago->isConfigured()) {
                $payment = $mercadoPago->findApprovedPayment($serviceRequest->id);
                if ($payment) {
                    app(BookingController::class)->approvePayment($serviceRequest, (string) $payment['id']);
                }
            }
        }

        return response()->json($this->present($serviceRequest->fresh()));
    }

    /**
     * E.4 — "Mis Exámenes": histórico descargable de informes.
     */
    public function results(): JsonResponse
    {
        $results = LabResult::where('user_id', auth()->id())
            ->orderByDesc('issued_at')
            ->limit(200)
            ->get()
            ->map(fn (LabResult $result) => [
                'id' => $result->id,
                'service_request_id' => $result->service_request_id,
                'title' => $result->title,
                'notes' => $result->notes,
                'file_name' => $result->file_name,
                'file_size' => $result->file_size,
                'issued_at' => $result->issued_at?->toIso8601String(),
                'emailed_at' => $result->emailed_at?->toIso8601String(),
                'download_url' => $result->download_url,
            ]);

        return response()->json($results);
    }

    /**
     * Enlace temporal firmado para abrir un informe fuera de la app.
     *
     * El visor de PDF del sistema no lleva el token del paciente, así que se
     * firma la URL por unos minutos en vez de dejar el informe accesible con
     * solo conocer la dirección.
     */
    public function resultLink(string $id): JsonResponse
    {
        $result = LabResult::where('user_id', auth()->id())->find($id);
        if ($result === null) {
            return response()->json(['error' => 'Informe no encontrado'], 404);
        }

        return response()->json([
            'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'lab-results.show',
                now()->addMinutes(10),
                ['resultId' => $result->id],
            ),
            'expires_in_seconds' => 600,
        ]);
    }

    /**
     * Serializa una toma de muestras para la app.
     */
    private function present(ServiceRequest $request): array
    {
        $professional = $request->professional_id
            ? ($request->relationLoaded('professional')
                ? $request->professional
                : Professional::find($request->professional_id))
            : null;

        $scheduledAt = $request->scheduled_at ? Carbon::parse($request->scheduled_at) : null;

        return [
            'id' => $request->id,
            'status' => $request->status,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'scheduled_label' => $scheduledAt ? $this->formatDateEs($scheduledAt) : null,
            'address_text' => $request->address_text,
            'zone' => $request->zone,
            'patient_type' => $request->patient_type,
            'dependent_id' => $request->dependent_id,
            'exam_required' => $request->exam_required,
            'clinical_notes' => $request->clinical_notes,
            'prescription_name' => $request->prescription_name,
            'prescription_file' => $request->prescription_url,
            'final_price' => (int) $request->final_price,
            'payment_url' => $request->payment_url,
            'payment_status' => $request->payment_status,
            'professional_name' => $professional?->name,
            'results_count' => LabResult::where('service_request_id', $request->id)->count(),
        ];
    }

    /**
     * D.8 (REQ-16) — Permite al paciente actualizar notas clínicas / ayuno antes de la toma.
     */
    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'clinical_notes' => 'required|string|max:1000',
        ]);

        $serviceRequest = ServiceRequest::where('user_id', auth()->id())
            ->where('is_scheduled', true)
            ->find($id);

        if (!$serviceRequest) {
            return response()->json(['error' => 'Solicitud de laboratorio no encontrada'], 404);
        }

        if (in_array($serviceRequest->status, ['in_progress', 'completed', 'cancelled'], true)) {
            return response()->json(['error' => 'No se pueden editar las indicaciones de una atención en curso o finalizada'], 422);
        }

        $serviceRequest->update([
            'clinical_notes' => $validated['clinical_notes'],
        ]);

        return response()->json([
            'success' => true,
            'clinical_notes' => $serviceRequest->clinical_notes,
        ]);
    }

    /**
     * Fecha legible en español, p. ej. "jueves 7 de agosto a las 08:30".
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
