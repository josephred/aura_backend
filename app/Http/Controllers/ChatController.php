<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ServiceRequest;
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
            ->get();

        return response()->json($messages);
    }

    /**
     * Send a message and generate a simulated response.
     */
    public function store(Request $request, string $requestId): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string',
        ]);

        $serviceRequest = ServiceRequest::where('user_id', auth()->id())->where('id', $requestId)->first();
        if (!$serviceRequest) {
            return response()->json(['error' => 'Service request not found'], 404);
        }

        $timeStr = date('H:i');

        // 1. Save patient message
        $patientMessage = ChatMessage::create([
            'id' => 'msg_patient_' . time(),
            'service_request_id' => $requestId,
            'sender' => 'patient',
            'text' => $validated['text'],
            'timestamp' => $timeStr,
        ]);

        // 2. Determine simulated reply based on keywords
        $userTextLower = mb_strtolower($validated['text']);
        $replyText = 'Entendido. Ya voy con todos los insumos necesarios de grado clínico. Llego según el tiempo estipulado. Mantenga el hogar a una temperatura agradable, por favor.';

        if (str_contains($userTextLower, 'fiebre') || str_contains($userTextLower, 'temperatura')) {
            $replyText = 'Llevo un termómetro clínico calibrado e insumos para ayudar a controlar la temperatura inmediatamente a mi llegada.';
        } elseif (str_contains($userTextLower, 'dirección') || str_contains($userTextLower, 'calle') || str_contains($userTextLower, 'ubicacion') || str_contains($userTextLower, 'direccion')) {
            $replyText = 'Gracias por la aclaración, el GPS me indica la ruta óptima. Llego según el tiempo estipulado.';
        } elseif (str_contains($userTextLower, 'pago') || str_contains($userTextLower, 'pagar') || str_contains($userTextLower, 'precio')) {
            $replyText = 'No se preocupe, visualizo que su pago ya fue procesado a través de su cuenta de forma 100% segura. No debe abonar nada extra al personal.';
        }

        // 3. Save provider reply message in DB
        $providerMessage = ChatMessage::create([
            'id' => 'msg_reply_' . time(),
            'service_request_id' => $requestId,
            'sender' => 'provider',
            'text' => $replyText,
            'timestamp' => $timeStr,
        ]);

        app(\App\Services\FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            'Nuevo mensaje del equipo clínico',
            $replyText,
            ['booking_id' => $requestId, 'type' => 'chat'],
        );

        return response()->json([
            'patient_message' => $patientMessage,
            'provider_message' => $providerMessage
        ], 201);
    }
}
