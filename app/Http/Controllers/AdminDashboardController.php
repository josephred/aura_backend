<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Services\DispatchZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Operations panel — administration only.
 *
 * Deliberately holds no clinical workspace: attending patients, agendas and
 * video calls all live in the separate /doctor portal.
 */
class AdminDashboardController extends Controller
{
    private const OPEN_STATUSES = ['pending_payment', 'accepted', 'en_camino', 'en_atencion'];

    public function index()
    {
        return view('admin.dashboard', [
            'staffName' => session('staff_name', 'Equipo Aura'),
        ]);
    }

    /**
     * Headline numbers for the operations panel.
     */
    public function metrics(): JsonResponse
    {
        $onDuty = Professional::where('active', true)
            ->where('duty_status', 'disponible')
            ->count();
        $totalProfessionals = Professional::where('active', true)->count();

        $open = ServiceRequest::whereIn('status', self::OPEN_STATUSES)->get();

        $completedToday = ServiceRequest::where('status', 'completed')
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        $avgEta = (int) round((float) ($open->avg('eta_minutes') ?? 0));

        // Lo que nadie ha tomado. Es distinto de `open_requests`: una atención
        // en curso está abierta pero tiene profesional yendo. Estas no.
        $enCola = ServiceRequest::query()
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', ''))
            ->where('status', 'accepted')
            ->where('is_scheduled', false)
            ->get(['id', 'created_at', 'escalada_nivel']);

        $esperaMayor = (int) ($enCola
            ->map(fn ($req) => $req->created_at ? (int) $req->created_at->diffInMinutes(now()) : 0)
            ->max() ?? 0);

        return response()->json([
            'professionals_on_duty' => $onDuty,
            'professionals_total' => $totalProfessionals,
            'open_requests' => $open->count(),
            'completed_today' => $completedToday,
            'average_eta_minutes' => $avgEta,
            'pending_prescriptions' => $open
                ->whereNotNull('prescription_file')
                ->where('status', 'pending_payment')
                ->count(),
            // Cola sin dueño: el número que dice si el despacho voluntario
            // está funcionando. `needs_operations` son las que ya pasaron el
            // segundo corte y esperan que una persona intervenga.
            'queued_requests' => $enCola->count(),
            'escalated_requests' => $enCola->where('escalada_nivel', '>=', 1)->count(),
            'needs_operations' => $enCola->where('escalada_nivel', '>=', 2)->count(),
            'longest_wait_minutes' => $esperaMayor,
        ]);
    }

    /**
     * Load per zone: how many requests are open and who is on duty there.
     */
    public function zones(DispatchZoneService $dispatch): JsonResponse
    {
        $open = ServiceRequest::whereIn('status', self::OPEN_STATUSES)->get();

        $zones = $open
            ->groupBy(fn ($request) => $request->zone ?: 'General')
            ->map(function ($requests, $zone) use ($dispatch) {
                $serviceId = $requests->first()->service_id;

                return [
                    'zone' => $zone,
                    'open_requests' => $requests->count(),
                    'professionals_on_duty' => $dispatch->availableProfessionals($serviceId, $zone),
                    'services' => $requests
                        ->groupBy('service_id')
                        ->map->count(),
                ];
            })
            ->values()
            ->sortByDesc('open_requests')
            ->values();

        return response()->json($zones);
    }

    /**
     * Every professional with their duty status, coverage and portal account.
     */
    public function professionals(): JsonResponse
    {
        $professionals = Professional::orderBy('name')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'specialty' => $p->specialty,
            'active' => (bool) $p->active,
            'duty_status' => $p->duty_status ?? 'disponible',
            'coverage_zones' => $p->coverage_zones,
            'email' => $p->email,
            'role' => $p->role ?? 'professional',
            'has_password' => !empty($p->password),
            'last_login_at' => $p->last_login_at?->toIso8601String(),
        ]);

        return response()->json($professionals);
    }

    /**
     * Change a professional's duty status and the zones they cover.
     */
    public function updateProfessional(Request $request, string $id): JsonResponse
    {
        $professional = Professional::find($id);
        if (!$professional) {
            return response()->json(['error' => 'Profesional no encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:150',
            'specialty' => 'nullable|string|max:150',
            'bio' => 'nullable|string|max:2000',
            'registration_number' => 'nullable|string|max:100',
            'years_of_experience' => 'nullable|integer|min:0|max:80',
            'phone' => 'nullable|string|max:30',
            'photo_url' => 'nullable|string|max:1000',
            'duty_status' => 'nullable|string|in:disponible,ocupado,desconectado',
            'coverage_zones' => 'nullable',
            'active' => 'nullable|boolean',
        ]);

        if (array_key_exists('coverage_zones', $validated) && is_string($validated['coverage_zones'])) {
            $decoded = json_decode($validated['coverage_zones'], true);
            $validated['coverage_zones'] = is_array($decoded)
                ? $decoded
                : array_map('trim', explode(',', $validated['coverage_zones']));
        }

        $professional->update(array_filter(
            $validated,
            fn ($value) => $value !== null,
        ));

        return response()->json(['success' => true, 'professional' => $professional->fresh()]);
    }

    /**
     * Create or reset a professional's portal login.
     * Returns the generated password when none was supplied.
     */
    public function saveAccount(Request $request, string $id): JsonResponse
    {
        $professional = Professional::find($id);
        if (!$professional) {
            return response()->json(['error' => 'Profesional no encontrado'], 404);
        }

        $validated = $request->validate([
            'email' => 'required|email|unique:professionals,email,' . $id,
            'password' => 'nullable|string|min:8',
        ]);

        $generated = null;
        $password = $validated['password'] ?? null;
        if (empty($password)) {
            $generated = Str::random(12);
            $password = $generated;
        }

        $professional->forceFill([
            'email' => strtolower($validated['email']),
            'password' => Hash::make($password),
        ])->save();

        return response()->json([
            'success' => true,
            'generated_password' => $generated,
        ]);
    }
}
