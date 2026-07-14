<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ClinicalService;
use App\Models\Dependent;
use App\Models\ChatMessage;
use App\Models\PastService;
use App\Services\FcmService;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class BookingController extends Controller
{
    /**
     * Get the active service request for the user.
     */
    public function active(): JsonResponse
    {
        $active = ServiceRequest::where('user_id', auth()->id())
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
            'symptoms_description' => 'nullable|string',
            'prescription_name' => 'nullable|string',
            'prescription_preview' => 'nullable|string',
            'prescription_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'exam_required' => 'nullable|string',
            'final_price' => 'required|integer',
            'eta_minutes' => 'required|integer',
        ]);

        // Persist the real prescription upload (multipart) and expose a public
        // URL. When no file is attached (JSON booking) this stays null and the
        // legacy `prescription_preview` string is kept for backward compat.
        $prescriptionFileUrl = null;
        if ($request->hasFile('prescription_file')) {
            $storedPath = $request->file('prescription_file')->store('prescriptions', 'public');
            $prescriptionFileUrl = Storage::disk('public')->url($storedPath);
        }

        // Cancel any existing active request
        ServiceRequest::where('user_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'cancelled', 'current_step' => 0]);

        $timeStr = date('H:i');
        $requestId = 'req_' . time();

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
            'symptoms_description' => $validated['symptoms_description'] ?? null,
            'prescription_name' => $validated['prescription_name'] ?? null,
            // Point the preview at the stored public URL when a file was
            // uploaded so the app and the doctor portal can render it.
            'prescription_preview' => $prescriptionFileUrl ?? ($validated['prescription_preview'] ?? null),
            'prescription_file' => $prescriptionFileUrl,
            'exam_required' => $validated['exam_required'] ?? null,
            'payment_method' => 'mercadopago',
            'final_price' => $validated['final_price'],
            'start_time' => $timeStr,
            'eta_minutes' => $validated['eta_minutes'],
            'current_step' => 0,
        ]);

        $service = ClinicalService::find($validated['service_id']);
        $serviceTitle = $service ? $service->short_title : 'Servicio';

        $mercadoPago = app(MercadoPagoService::class);
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
        }

        // Gateway not configured or unreachable: activate immediately (simulated payment)
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

        ChatMessage::create([
            'id' => 'm1_' . time(),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => "Canal clínico seguro iniciado para: $serviceTitle.",
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
     * Simulate the next step in the active request.
     */
    public function simulateStep(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::where('user_id', auth()->id())->where('id', $id)->first();

        if (!$serviceRequest) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        $currentStep = $serviceRequest->current_step;
        $nextStatus = '';
        $nextStep = 0;

        if ($currentStep === 1) {
            $nextStatus = 'en_camino';
            $nextStep = 2;
        } elseif ($currentStep === 2) {
            $nextStatus = 'en_atencion';
            $nextStep = 3;
        } elseif ($currentStep === 3) {
            $nextStatus = 'completed';
            $nextStep = 4;
        } else {
            return response()->json($serviceRequest);
        }

        $serviceRequest->update([
            'status' => $nextStatus,
            'current_step' => $nextStep,
        ]);

        $stepNotifications = [
            2 => ['En camino', 'El profesional va en camino a tu domicilio.'],
            3 => ['Atención en curso', 'El profesional llegó y la atención está en curso.'],
            4 => ['Atención completada', 'Tu atención finalizó. El resumen ya está en tu historial.'],
        ];
        [$notifTitle, $notifBody] = $stepNotifications[$nextStep];
        app(FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            $notifTitle,
            $notifBody,
            ['booking_id' => $serviceRequest->id, 'status' => $nextStatus],
        );

        $timeStr = date('H:i');

        // Add simulated chat message based on transition
        if ($nextStep === 2) {
            ChatMessage::create([
                'id' => 'msg_step2_' . time(),
                'service_request_id' => $id,
                'sender' => 'provider',
                'text' => 'He ingresado a la autopista principal. El tráfico es moderado, voy en camino directo a tu domicilio.',
                'timestamp' => $timeStr,
            ]);
        } elseif ($nextStep === 3) {
            ChatMessage::create([
                'id' => 'msg_step3_' . time(),
                'service_request_id' => $id,
                'sender' => 'provider',
                'text' => 'Acabo de llegar al domicilio. Por favor, indíqueme el número de timbre o si hay conserjería para anunciar mi ingreso.',
                'timestamp' => $timeStr,
            ]);
        } elseif ($nextStep === 4) {
            ChatMessage::create([
                'id' => 'msg_step4_' . time(),
                'service_request_id' => $id,
                'sender' => 'system',
                'text' => 'Atención completada con éxito. Resumen médico disponible en el historial.',
                'timestamp' => $timeStr,
            ]);

            // Save past service record upon completion
            $service = ClinicalService::find($serviceRequest->service_id);
            $serviceTitle = $service ? $service->title : 'Atención Médica';

            $patientName = 'Usuario Principal';
            if ($serviceRequest->patient_type === 'dependent' && $serviceRequest->dependent_id) {
                $dep = Dependent::find($serviceRequest->dependent_id);
                if ($dep) {
                    $patientName = "{$dep->name} ({$dep->relationship})";
                }
            }

            $professionals = [
                'enfermeria' => ['Enf. Cristian Valenzuela', 'Enf. Patricia Jara', 'Enf. Rodrigo Montes'],
                'medico' => ['Dra. Camila Rivera N. (Médico Internista)', 'Dr. Sebastián Leyton (Médico General)'],
                'kine_motora' => ['Klgo. Ignacio Orellana', 'Klga. Maria José Díaz'],
                'kine_respiratoria' => ['Klgo. Mauricio Pinilla', 'Klga. Solange Arancibia'],
                'cuidados' => ['Cuidadora Julia Valdés', 'Cuidador Esteban Muñoz'],
                'ambulancia' => ['Paramédico Carlos Rojas', 'Conductor Manuel Guerrero'],
                'radiologia' => ['Tec. Radiólogo Daniel Gatica'],
                'laboratorio' => ['Enf. Francisca Soto', 'Tec. Lab. Marcelo Castro'],
                'electrocardiograma' => ['Enf. Cristián Valenzuela', 'Dra. Camila Rivera N.'],
            ];

            $serviceKey = $serviceRequest->service_id;
            $staffList = $professionals[$serviceKey] ?? ['Personal Clínico de Aura'];
            $assignedStaff = $staffList[array_rand($staffList)];

            $summaries = [
                'enfermeria' => 'Procedimiento de enfermería realizado en domicilio según indicaciones médicas. Se administraron fármacos o curó herida respetando medidas de asepsia. Signos vitales estables.',
                'medico' => 'Consulta médica general domiciliaria por síntomas agudos. Paciente evaluado exhaustivamente, se entregó receta médica digital y recomendaciones terapéuticas.',
                'kine_motora' => 'Sesión de kinesiología motora enfocada en ejercicios terapéuticos de movilidad, marcha y fortalecimiento muscular. Buena tolerancia del paciente.',
                'kine_respiratoria' => 'Sesión de kinesioterapia bronquial respiratoria, se aplicaron técnicas de aseo bronquial y ejercicios ventilatorios. Paciente refiere alivio de congestión.',
                'cuidados' => 'Asistencia y compañía clínica integral para confort del paciente. Control de fármacos orales, movilización segura y apoyo en higiene básica.',
                'ambulancia' => 'Traslado clínico programado finalizado exitosamente. Paciente movilizado de manera segura respetando protocolos de transporte clínico en camilla.',
                'radiologia' => 'Estudio de radiología portátil digitalizado realizado en domicilio. Imagenología cargada en el portal y enviada al médico tratante. Sin incidentes.',
                'laboratorio' => 'Toma de muestras sanguíneas y/o biológicas realizada en domicilio. Muestras refrigeradas y despachadas de inmediato al laboratorio central.',
                'electrocardiograma' => 'Electrocardiograma de 12 derivaciones en reposo completado con éxito. El trazado fue enviado a tele-cardiología para informe definitivo.',
            ];

            $summary = $summaries[$serviceKey] ?? 'Atención clínica domiciliaria realizada de manera conforme por el especialista de guardia.';

            // Month names mapping in Spanish
            $months = [
                'January' => 'Enero', 'February' => 'Febrero', 'March' => 'Marzo',
                'April' => 'Abril', 'May' => 'Mayo', 'June' => 'Junio',
                'July' => 'Julio', 'August' => 'Agosto', 'September' => 'Septiembre',
                'October' => 'Octubre', 'November' => 'Noviembre', 'December' => 'Diciembre'
            ];
            $currentMonthEnglish = date('F');
            $spanishMonth = $months[$currentMonthEnglish] ?? $currentMonthEnglish;
            $dateStr = date('d \d\e ') . $spanishMonth . date(' Y');

            PastService::create([
                'id' => 'past_' . time(),
                'user_id' => auth()->id(),
                'service_id' => $serviceRequest->service_id,
                'service_title' => $serviceTitle,
                'date' => $dateStr,
                'patient' => $patientName,
                'price' => $serviceRequest->final_price,
                'status' => 'completed',
                'details' => $summary,
                'professional' => $assignedStaff,
            ]);
        }

        return response()->json($serviceRequest);
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

            // Loop for up to 50 seconds to avoid exceeding webserver timeout limits
            $elapsed = 0;
            while ($elapsed < 50) {
                $serviceRequest = \App\Models\ServiceRequest::find($id);

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

                if ($serviceRequest->status !== $lastStatus || $serviceRequest->current_step !== $lastStep || $messageCount !== $lastMessageCount) {
                    $lastStatus = $serviceRequest->status;
                    $lastStep = $serviceRequest->current_step;
                    $lastMessageCount = $messageCount;

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
