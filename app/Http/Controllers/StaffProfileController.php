<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Services\DispatchZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The professional's own shift, as seen from the mobile app.
 */
class StaffProfileController extends Controller
{
    use Concerns\ResolvesStaffScope;

    /**
     * Who am I, what zones do I cover, and how is my shift going today.
     */
    public function show(Request $request, DispatchZoneService $zones): JsonResponse
    {
        $user = $request->user();
        $professional = $this->scopedProfessionalId()
            ? Professional::find($this->scopedProfessionalId())
            : null;

        $completedToday = $professional
            ? ServiceRequest::where('professional_id', $professional->id)
                ->where('status', 'completed')
                ->whereDate('updated_at', now()->toDateString())
                ->count()
            : 0;

        $openNow = $professional
            ? ServiceRequest::where('professional_id', $professional->id)
                ->whereIn('status', ['accepted', 'en_camino', 'en_atencion'])
                ->count()
            : 0;

        return response()->json([
            'name' => $professional?->name ?? $user->name,
            'specialty' => $professional?->specialty,
            'role' => $user->role,
            'is_operator' => $user->isOperator(),
            'professional_id' => $professional?->id,
            'duty_status' => $professional?->duty_status ?? 'desconectado',
            'coverage_zones' => $professional
                ? $zones->zonesCoveredBy($professional)
                : [],
            'completed_today' => $completedToday,
            'open_now' => $openNow,
        ]);
    }

    /**
     * Go on or off shift from the app.
     *
     * Going off duty is refused while a visit is under way: the patient is
     * waiting for this professional and nobody else can pick it up.
     */
    public function updateDuty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'duty_status' => 'required|string|in:disponible,desconectado',
        ]);

        $professionalId = $this->scopedProfessionalId();
        $professional = $professionalId ? Professional::find($professionalId) : null;

        if (!$professional) {
            return response()->json(['error' => 'Sin ficha de profesional asociada'], 403);
        }

        if ($validated['duty_status'] === 'desconectado') {
            $busy = ServiceRequest::where('professional_id', $professional->id)
                ->whereIn('status', ['accepted', 'en_camino', 'en_atencion'])
                ->exists();

            if ($busy) {
                return response()->json([
                    'error' => 'Tienes una atención en curso. Ciérrala antes de salir de turno.',
                ], 422);
            }
        }

        $professional->update(['duty_status' => $validated['duty_status']]);

        return response()->json([
            'success' => true,
            'duty_status' => $professional->fresh()->duty_status,
        ]);
    }
}
