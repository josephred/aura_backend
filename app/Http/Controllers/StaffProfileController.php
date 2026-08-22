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
        $profId = $this->scopedProfessionalId() ?? $user?->professional_id;
        $professional = $profId ? Professional::find($profId) : null;

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
            'bio' => $professional?->bio,
            'registration_number' => $professional?->registration_number,
            'years_of_experience' => $professional?->years_of_experience,
            'phone' => $professional?->phone,
            'photo_url' => $professional?->photo_url,
            'provides_lab' => (bool) ($professional?->provides_lab ?? false),
            'rating_avg' => $professional?->rating_avg,
            'rating_count' => $professional?->rating_count ?? 0,
        ]);
    }

    /**
     * Update professional profile/resume (bio, registration number, experience,
     * phone, photo, coverage zones).
     */
    public function update(Request $request, DispatchZoneService $zones): JsonResponse
    {
        $professionalId = $this->scopedProfessionalId();
        $professional = $professionalId ? Professional::find($professionalId) : null;

        if (!$professional) {
            return response()->json(['error' => 'Sin ficha de profesional asociada'], 403);
        }

        $validated = $request->validate([
            'bio' => 'nullable|string|max:2000',
            'registration_number' => 'nullable|string|max:100',
            'years_of_experience' => 'nullable|integer|min:0|max:80',
            'phone' => 'nullable|string|max:30',
            'coverage_zones' => 'nullable',
            'photo' => 'nullable|file|image|max:5120',
            'photo_url' => 'nullable|string|max:1000',
        ]);

        $attributes = [];

        if (array_key_exists('bio', $validated)) {
            $attributes['bio'] = $validated['bio'];
        }
        if (array_key_exists('registration_number', $validated)) {
            $attributes['registration_number'] = $validated['registration_number'];
        }
        if (array_key_exists('years_of_experience', $validated)) {
            $attributes['years_of_experience'] = $validated['years_of_experience'];
        }
        if (array_key_exists('phone', $validated)) {
            $attributes['phone'] = $validated['phone'];
        }
        if (array_key_exists('coverage_zones', $validated)) {
            $zonesValue = $validated['coverage_zones'];
            if (is_array($zonesValue)) {
                $attributes['coverage_zones'] = implode(', ', array_filter(array_map('trim', $zonesValue)));
            } elseif (is_string($zonesValue)) {
                $attributes['coverage_zones'] = $zonesValue;
            }
        }

        if ($request->hasFile('photo')) {
            $disk = config('aura.media.public_disk', 'public');
            $path = $request->file('photo')->store('professionals/photos', $disk);
            $attributes['photo_url'] = $disk === 'public'
                ? '/storage/' . $path
                : \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
        } elseif (array_key_exists('photo_url', $validated)) {
            $attributes['photo_url'] = $validated['photo_url'];
        }

        $professional->update($attributes);
        $professional->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente.',
            'profile' => [
                'id' => $professional->id,
                'name' => $professional->name,
                'specialty' => $professional->specialty,
                'bio' => $professional->bio,
                'registration_number' => $professional->registration_number,
                'years_of_experience' => $professional->years_of_experience,
                'phone' => $professional->phone,
                'photo_url' => $professional->photo_url,
                'provides_lab' => (bool) $professional->provides_lab,
                'coverage_zones' => $zones->zonesCoveredBy($professional),
                'duty_status' => $professional->duty_status,
                'rating_avg' => $professional->rating_avg,
                'rating_count' => $professional->rating_count ?? 0,
            ],
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
