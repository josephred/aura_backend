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
     * Store a message from the patient. The assigned professional answers it
     * from the portal; nothing is auto-generated here.
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

        // No automatic reply is generated. The professional attending the
        // request reads and answers from the portal, which polls this thread.
        //
        // The previous keyword bot competed with the real professional (the
        // patient received two answers to every message) and one of its canned
        // replies asserted "visualizo que su pago ya fue procesado" without
        // ever checking the payment status.

        return response()->json([
            'patient_message' => $patientMessage,
        ], 201);
    }
}
