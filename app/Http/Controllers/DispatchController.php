<?php

namespace App\Http\Controllers;

use App\Services\DispatchZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    public function __construct(private readonly DispatchZoneService $zones)
    {
    }

    /**
     * Wait estimate for a service in the caller's zone, based on how many
     * requests are open there and how many professionals are on duty.
     *
     * GET /api/dispatch/eta?service_id=medico&address=Los Alerces 1420, Providencia
     */
    public function eta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|string|exists:clinical_services,id',
            'address' => 'nullable|string',
            'zone' => 'nullable|string',
        ]);

        return response()->json($this->zones->estimate(
            $validated['service_id'],
            $validated['address'] ?? null,
            $validated['zone'] ?? null,
        ));
    }
}
