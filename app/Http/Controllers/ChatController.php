<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    /**
     * Get chat messages for a specific request.
     */
    public function index(string $requestId): JsonResponse
    {
        $ownsRequest = ServiceRequest::where('user_id', auth()->id())->where('id', $requestId)->exists();
        if (!$ownsRequest) {
            return response()->json(['error' => 'Service request not found'], 404);
        }

        $messages = ChatMessage::where('service_request_id', $requestId)
            ->orderBy('created_at', 'asc')
            // Desempate estable: `created_at` guarda segundos, y dos mensajes
            // del mismo segundo salian en orden arbitrario. Sin esto el
            // paciente y el portal podian ver el mismo par al reves.
            ->orderBy('id')
            ->get();

        return response()->json($messages);
    }

    /**
     * Store a message from the patient. The assigned professional answers it
     * from the portal; nothing is auto-generated here.
     */
    public function store(Request $request, string $requestId): JsonResponse
    {
        $validated = $request->validate([
            // El mismo limite que aplica el portal al responder: sin tope, un
            // mensaje del paciente podia entrar con un tamano que el portal
            // nunca podria devolver.
            'text' => 'required|string|max:1000',
        ]);

        $serviceRequest = ServiceRequest::where('user_id', auth()->id())->where('id', $requestId)->first();
        if (!$serviceRequest) {
            return response()->json(['error' => 'Service request not found'], 404);
        }

        $timeStr = date('H:i');

        // 1. Save patient message
        $patientMessage = ChatMessage::create([
            'id' => ChatMessage::nextId('msg_patient'),
            'service_request_id' => $requestId,
            'sender' => 'patient',
            'text' => $validated['text'],
            'timestamp' => $timeStr,
        ]);

        // No automatic reply is generated. The professional attending the
        // request reads and answers from the portal, which polls this thread.
        //
        // The previous keyword bot competed with the real professional (the
        // patient received two answers to every message) and one of its canned
        // replies asserted "visualizo que su pago ya fue procesado" without
        // ever checking the payment status.

        // El portal solo refresca el hilo de la reserva que el profesional
        // tenga abierta en ese momento. Sin este aviso, escribir con la ficha
        // cerrada equivalia a no escribir: el mensaje quedaba esperando a que
        // alguien la seleccionara. El sentido inverso (profesional -> paciente)
        // ya notificaba desde DoctorDashboardController.
        $this->notifyAssignedProfessional($serviceRequest, $validated['text']);

        return response()->json([
            'patient_message' => $patientMessage,
        ], 201);
    }

    /**
     * Push al profesional que lleva la atencion, si tiene cuenta de app.
     *
     * Silencioso a proposito: mientras la solicitud siga en la guardia sin
     * tomar no hay a quien avisar, y FcmService ya no hace nada cuando
     * Firebase no esta configurado.
     */
    private function notifyAssignedProfessional(ServiceRequest $serviceRequest, string $text): void
    {
        if (empty($serviceRequest->professional_id)) {
            return;
        }

        $staffUserIds = User::where('professional_id', $serviceRequest->professional_id)
            ->pluck('id');

        // Quien envia es el paciente autenticado: no hace falta releerlo.
        $patientName = auth()->user()?->name ?: 'Paciente';

        foreach ($staffUserIds as $staffUserId) {
            app(FcmService::class)->notifyUser(
                $staffUserId,
                "Mensaje de $patientName",
                $text,
                ['booking_id' => $serviceRequest->id, 'type' => 'chat'],
            );
        }
    }
}
