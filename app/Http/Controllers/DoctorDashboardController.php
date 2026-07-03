<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ClinicalService;
use App\Models\User;
use App\Models\Dependent;
use App\Models\ChatMessage;
use App\Models\PastService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DoctorDashboardController extends Controller
{
    /**
     * Display the doctor dashboard.
     */
    public function index()
    {
        return view('doctor.dashboard');
    }

    /**
     * Get all active and pending bookings for the dashboard.
     */
    public function bookings(): JsonResponse
    {
        $bookings = ServiceRequest::orderBy('created_at', 'desc')->get()->map(function ($req) {
            $service = ClinicalService::find($req->service_id);
            $user = User::find($req->user_id);
            $dependent = $req->dependent_id ? Dependent::find($req->dependent_id) : null;

            return [
                'id' => $req->id,
                'user_id' => $req->user_id,
                'service_id' => $req->service_id,
                'status' => $req->status,
                'patient_type' => $req->patient_type,
                'dependent_id' => $req->dependent_id,
                'address_text' => $req->address_text,
                'origin_address' => $req->origin_address,
                'destination_address' => $req->destination_address,
                'ambulance_type' => $req->ambulance_type,
                'symptoms_description' => $req->symptoms_description,
                'final_price' => $req->final_price,
                'start_time' => $req->start_time,
                'eta_minutes' => $req->eta_minutes,
                'current_step' => $req->current_step,
                'created_at' => $req->created_at ? $req->created_at->toIso8601String() : null,
                'service' => $service,
                'user' => $user,
                'dependent' => $dependent,
            ];
        });

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

        $serviceRequest = ServiceRequest::find($id);
        if (!$serviceRequest) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

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

        $timeStr = date('H:i');

        // Post chat updates corresponding to the step
        if ($nextStatus === 'accepted') {
            ChatMessage::create([
                'id' => 'web_msg_step1_' . time(),
                'service_request_id' => $id,
                'sender' => 'provider',
                'text' => 'Hola, soy tu especialista clínico asignado. Ya estoy preparando el equipamiento para salir hacia tu dirección.',
                'timestamp' => $timeStr,
            ]);
        } elseif ($nextStatus === 'en_camino') {
            ChatMessage::create([
                'id' => 'web_msg_step2_' . time(),
                'service_request_id' => $id,
                'sender' => 'provider',
                'text' => 'He iniciado el trayecto hacia tu ubicación. Voy en camino directo.',
                'timestamp' => $timeStr,
            ]);
        } elseif ($nextStatus === 'en_atencion') {
            ChatMessage::create([
                'id' => 'web_msg_step3_' . time(),
                'service_request_id' => $id,
                'sender' => 'provider',
                'text' => 'He llegado al domicilio. Estoy tocando el timbre para ingresar.',
                'timestamp' => $timeStr,
            ]);
        } elseif ($nextStatus === 'completed') {
            ChatMessage::create([
                'id' => 'web_msg_step4_' . time(),
                'service_request_id' => $id,
                'sender' => 'system',
                'text' => 'Atención completada con éxito. Resumen médico disponible en el historial.',
                'timestamp' => $timeStr,
            ]);

            // Replicate PastService saving logic
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

            $staffList = $professionals[$serviceRequest->service_id] ?? ['Personal Clínico de Aura'];
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

            $summary = $summaries[$serviceRequest->service_id] ?? 'Atención clínica domiciliaria realizada de manera conforme por el especialista de guardia.';

            $months = [
                'January' => 'Enero', 'February' => 'Febrero', 'March' => 'Marzo',
                'April' => 'Abril', 'May' => 'Mayo', 'June' => 'Junio',
                'July' => 'Julio', 'August' => 'Agosto', 'September' => 'Septiembre',
                'October' => 'Octubre', 'November' => 'Noviembre', 'December' => 'Diciembre'
            ];
            $monthEn = date('F');
            $monthEs = $months[$monthEn] ?? $monthEn;
            $dateFormatted = date('d \d\e ') . $monthEs;

            PastService::create([
                'id' => 'past_' . time() . '_' . rand(100, 999),
                'user_id' => $serviceRequest->user_id,
                'service_title' => $serviceTitle,
                'professional_name' => $assignedStaff,
                'patient_name' => $patientName,
                'date_str' => $dateFormatted,
                'summary' => $summary,
            ]);
        }

        return response()->json([
            'success' => true,
            'booking' => $serviceRequest,
        ]);
    }

    /**
     * Get chat messages for a specific booking.
     */
    public function getMessages(string $id): JsonResponse
    {
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

        $serviceRequest = ServiceRequest::find($id);
        if (!$serviceRequest) {
            return response()->json(['error' => 'Reserva no encontrada'], 404);
        }

        $message = ChatMessage::create([
            'id' => 'web_msg_' . time() . '_' . rand(100, 999),
            'service_request_id' => $id,
            'sender' => 'provider',
            'text' => $validated['text'],
            'timestamp' => date('H:i'),
        ]);

        return response()->json($message, 201);
    }
}
