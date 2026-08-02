<?php

namespace App\Services;

use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zone-based dispatch.
 *
 * Aura does not chase one specific doctor: a request lands in the queue of a
 * geographic zone (comuna) and is served by whichever professional of that
 * discipline is on duty there. This service resolves the zone of an address
 * and turns "how busy is that zone right now" into a wait estimate the patient
 * sees before confirming.
 */
class DispatchZoneService
{
    /** Statuses that still consume a professional's time. */
    private const OPEN_STATUSES = ['pending_payment', 'accepted', 'en_camino', 'en_atencion'];

    /**
     * How long an unpaid request keeps counting towards the zone's load.
     *
     * The payment confirmation step means requests legitimately sit in
     * `pending_payment` while the patient reads the amount. Past this window
     * we assume the checkout was abandoned: it stops weighing on the ETA and
     * `bookings:expire-unpaid` eventually cancels it.
     */
    public const PENDING_PAYMENT_GRACE_MINUTES = 20;

    /**
     * Comunas we recognise in a free-text address, in the order we try them.
     * Longer names first so "San Miguel" wins over "Miguel".
     */
    private const KNOWN_ZONES = [
        'Lo Barnechea', 'Las Condes', 'La Florida', 'La Reina', 'San Miguel',
        'San Bernardo', 'Puente Alto', 'Estación Central', 'Quinta Normal',
        'Pedro Aguirre Cerda', 'Padre Hurtado', 'Providencia', 'Vitacura',
        'Ñuñoa', 'Santiago', 'Macul', 'Peñalolén', 'Maipú', 'Recoleta',
        'Independencia', 'Huechuraba', 'Quilicura', 'Renca', 'Cerrillos',
        'Conchalí', 'La Cisterna', 'El Bosque', 'La Granja', 'Lo Espejo',
        'Cerro Navia', 'Lo Prado', 'Pudahuel', 'Colina', 'Buin', 'Talagante',
    ];

    /**
     * Which professional specialties can serve each clinical service.
     * Matching is done case- and accent-insensitively on a normalized string.
     */
    private const SERVICE_SPECIALTIES = [
        'medico' => ['medicina', 'medico'],
        'enfermeria' => ['enfermeria'],
        'kine_motora' => ['kinesiologia'],
        'kine_respiratoria' => ['kinesiologia'],
        'cuidados' => ['enfermeria', 'cuidados'],
        'laboratorio' => ['enfermeria', 'laboratorio'],
        'electrocardiograma' => ['enfermeria', 'medicina'],
        'radiologia' => ['radiologia', 'imagenologia'],
        'ambulancia' => ['ambulancia', 'paramedico'],
    ];

    /**
     * Zone for a request, preferring the typed address and falling back to
     * reverse geocoding the map pin.
     *
     * Typed text wins when it already names a comuna: it costs nothing and the
     * patient just confirmed it. Coordinates are only consulted when the text
     * is ambiguous ("Ubicación seleccionada", a street with no comuna), which
     * is exactly the case that used to end up in 'General' and quote the load
     * of the whole city.
     */
    public function resolveZoneFor(?string $addressText, ?float $lat, ?float $lng): string
    {
        $zone = $this->resolveZone($addressText);

        if ($zone !== 'General' || $lat === null || $lng === null) {
            return $zone;
        }

        return $this->resolveZoneFromCoordinates($lat, $lng);
    }

    /**
     * Reverse-geocodes a point into a known comuna.
     *
     * Uses OpenStreetMap's Nominatim, consistent with the map tiles and the
     * OSRM routing the app already relies on. Results are cached for a day
     * because a coordinate's comuna does not change, and because Nominatim's
     * usage policy caps callers at roughly one request per second.
     */
    public function resolveZoneFromCoordinates(float $lat, float $lng): string
    {
        // ~11 m of precision: enough to identify a comuna, coarse enough for
        // nearby requests to share a cache entry.
        $key = sprintf('dispatch-zone:%.4f,%.4f', $lat, $lng);

        return Cache::remember($key, now()->addDay(), function () use ($lat, $lng) {
            $endpoint = config('services.nominatim.url', 'https://nominatim.openstreetmap.org/reverse');

            try {
                $response = Http::withHeaders([
                    // Nominatim rejects anonymous clients.
                    'User-Agent' => config('services.nominatim.user_agent', 'AuraSalud/1.0'),
                    'Accept-Language' => 'es',
                ])->timeout(5)->get($endpoint, [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 10, // administrative level of a comuna
                ]);

                if (!$response->successful()) {
                    return 'General';
                }

                $address = $response->json('address') ?? [];
            } catch (\Throwable $e) {
                Log::warning('Reverse geocoding failed', ['error' => $e->getMessage()]);

                return 'General';
            }

            // Nominatim labels Chilean comunas inconsistently across these keys.
            $candidates = array_filter([
                $address['city_district'] ?? null,
                $address['municipality'] ?? null,
                $address['town'] ?? null,
                $address['city'] ?? null,
                $address['suburb'] ?? null,
                $address['county'] ?? null,
            ]);

            foreach ($candidates as $candidate) {
                $match = $this->matchKnownZone((string) $candidate);
                if ($match !== null) {
                    return $match;
                }
            }

            return 'General';
        });
    }

    /**
     * Maps a free-form place name onto our list of served comunas.
     */
    private function matchKnownZone(string $candidate): ?string
    {
        $normalized = $this->normalize($candidate);

        foreach (self::KNOWN_ZONES as $zone) {
            if ($normalized === $this->normalize($zone)) {
                return $zone;
            }
        }

        // "Comuna de Providencia" and similar wrappers.
        foreach (self::KNOWN_ZONES as $zone) {
            if (str_contains($normalized, $this->normalize($zone))) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Best-effort zone for a free-text address. Returns 'General' when no
     * known comuna is mentioned.
     */
    public function resolveZone(?string $addressText): string
    {
        if (!$addressText) {
            return 'General';
        }

        $haystack = $this->normalize($addressText);

        foreach (self::KNOWN_ZONES as $zone) {
            if (str_contains($haystack, $this->normalize($zone))) {
                return $zone;
            }
        }

        return 'General';
    }

    /**
     * Current load and wait estimate for a service in a zone.
     *
     * @return array{
     *   zone:string, service_id:string, waiting:int, in_progress:int,
     *   available_professionals:int, demand_level:string,
     *   eta_min_minutes:int, eta_max_minutes:int, message:string
     * }
     */
    public function estimate(string $serviceId, ?string $addressText = null, ?string $zone = null): array
    {
        $zone = $zone ?: $this->resolveZone($addressText);
        $service = ClinicalService::find($serviceId);

        [$baseMin, $baseMax] = $this->baseEtaRange($service?->base_eta);

        $openRequests = ServiceRequest::where('service_id', $serviceId)
            ->where(function ($query) {
                // A request only counts as real load once it is paid. Someone
                // still deciding on the payment screen — or who simply closed
                // the app — must not inflate everyone else's wait forever.
                $query->whereIn('status', ['accepted', 'en_camino', 'en_atencion'])
                    ->orWhere(function ($pending) {
                        $pending->where('status', 'pending_payment')
                            ->where('created_at', '>=', now()->subMinutes(self::PENDING_PAYMENT_GRACE_MINUTES));
                    });
            });

        // 'General' means "we could not geocode": look at the whole city so the
        // estimate still reflects reality instead of pretending there is no load.
        if ($zone !== 'General') {
            $openRequests->where('zone', $zone);
        }

        $openRequests = $openRequests->get();

        $waiting = $openRequests->whereIn('status', ['pending_payment', 'accepted'])->count();
        $inProgress = $openRequests->whereIn('status', ['en_camino', 'en_atencion'])->count();

        // Capacity is everyone on shift (free *or* mid-visit). Someone busy
        // right now still clears the queue soon, so they must not be treated
        // like an empty zone — that is what the extra queue round models.
        $onDuty = $this->professionalsForZone($serviceId, $zone)->count();
        $free = $this->professionalsForZone($serviceId, $zone, onlyFree: true)->count();

        // Each professional can only take one visit at a time. Every batch that
        // does not fit in the current shift waits a full extra service cycle.
        // ceil (not floor): with 2 on shift and 3 open requests the third
        // patient really does wait another round — rounding down would announce
        // "alta demanda" while quoting the same time as an empty zone.
        $capacity = max(1, $onDuty);
        $overflow = max(0, $waiting + $inProgress - $capacity);
        $penalty = (int) ceil($overflow / $capacity) * $baseMin;

        // Nobody on shift in the zone: quote the contingency turnaround.
        if ($onDuty === 0) {
            $penalty += $baseMin;
        }

        $etaMin = $baseMin + $penalty;
        $etaMax = $baseMax + $penalty;

        $demandLevel = $this->demandLevel($waiting + $inProgress, $capacity, $onDuty);

        return [
            'zone' => $zone,
            'service_id' => $serviceId,
            'waiting' => $waiting,
            'in_progress' => $inProgress,
            'available_professionals' => $onDuty,
            'free_professionals' => $free,
            'demand_level' => $demandLevel,
            'eta_min_minutes' => $etaMin,
            'eta_max_minutes' => $etaMax,
            'message' => $this->message($zone, $demandLevel, $etaMin, $etaMax, $onDuty, $waiting + $inProgress),
        ];
    }

    /**
     * How many professionals of the right discipline are on shift in the zone.
     */
    public function availableProfessionals(string $serviceId, string $zone): int
    {
        return $this->professionalsForZone($serviceId, $zone)->count();
    }

    /**
     * Professionals of the right discipline covering the zone.
     *
     * @param bool $onlyFree Restrict to those not currently on a visit.
     * @return \Illuminate\Support\Collection<int, Professional>
     */
    public function professionalsForZone(string $serviceId, string $zone, bool $onlyFree = false)
    {
        $specialties = self::SERVICE_SPECIALTIES[$serviceId] ?? [];

        return Professional::query()
            ->where('active', true)
            ->whereIn('duty_status', $onlyFree ? ['disponible'] : ['disponible', 'ocupado'])
            ->get()
            ->filter(function (Professional $professional) use ($specialties, $zone) {
                if ($specialties !== []) {
                    $haystack = $this->normalize($professional->specialty ?? '');
                    $matches = false;
                    foreach ($specialties as $specialty) {
                        if (str_contains($haystack, $this->normalize($specialty))) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        return false;
                    }
                }

                // Single source of truth for coverage, shared with the portal.
                return $this->covers($professional, $zone);
            })
            ->values();
    }

    /**
     * Zones a professional covers, normalized. Empty means "works anywhere".
     *
     * @return array<int, string>
     */
    public function zonesCoveredBy(Professional $professional): array
    {
        $coverage = trim((string) $professional->coverage_zones);
        if ($coverage === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $zone) => trim($zone),
            explode(',', $coverage),
        )));
    }

    /**
     * True when [$zone] falls inside the professional's coverage.
     * A professional with no declared coverage matches every zone.
     */
    public function covers(Professional $professional, ?string $zone): bool
    {
        $covered = $this->zonesCoveredBy($professional);
        if ($covered === [] || $zone === null || $zone === '' || $zone === 'General') {
            return true;
        }

        foreach ($covered as $candidate) {
            if ($this->normalize($candidate) === $this->normalize($zone)) {
                return true;
            }
        }

        return false;
    }

    /** low | medium | high */
    private function demandLevel(int $load, int $capacity, int $onDuty): string
    {
        // An empty shift is the worst case regardless of how few requests are
        // queued: there is nobody to take them.
        if ($onDuty === 0) {
            return 'high';
        }

        $ratio = $load / max(1, $capacity);

        if ($ratio >= 1.5) {
            return 'high';
        }
        if ($ratio >= 0.75) {
            return 'medium';
        }

        return 'low';
    }

    private function message(string $zone, string $level, int $min, int $max, int $onDuty, int $load): string
    {
        $zoneLabel = $zone === 'General' ? 'tu zona' : $zone;

        if ($onDuty === 0) {
            return "Sin profesionales en turno en $zoneLabel en este momento. "
                . "Tu solicitud entra a la cola y la tomará el primero que inicie turno (aprox. $min-$max min).";
        }

        $staff = $onDuty === 1 ? '1 profesional en turno' : "$onDuty profesionales en turno";

        return match ($level) {
            'high' => "Alta demanda en $zoneLabel: $load atenciones en curso con $staff. Demora estimada $min-$max min.",
            'medium' => "Demanda moderada en $zoneLabel: $load atenciones en curso con $staff. Demora estimada $min-$max min.",
            default => "Buena disponibilidad en $zoneLabel: $staff. Demora estimada $min-$max min.",
        };
    }

    /**
     * Parses the "45 - 60" strings stored in clinical_services.base_eta.
     *
     * @return array{0:int,1:int}
     */
    private function baseEtaRange(?string $baseEta): array
    {
        if (!$baseEta) {
            return [30, 45];
        }

        preg_match_all('/\d+/', $baseEta, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        if ($numbers === []) {
            return [30, 45];
        }
        if (count($numbers) === 1) {
            return [$numbers[0], $numbers[0] + 15];
        }

        return [min($numbers), max($numbers)];
    }

    /** Lowercase, accent-free comparison key. */
    private function normalize(string $value): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];

        return mb_strtolower(strtr($value, $map));
    }

    /**
     * Calculates direct distance in km between origin and destination coordinates.
     */
    public function calculateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }

    /**
     * Calculates dynamic transport fee based on distance (base rate + price per km).
     */
    public function calculateTransportFee(float $distanceKm, int $baseFee = 5000, int $pricePerKm = 1200): int
    {
        return (int) round($baseFee + ($distanceKm * $pricePerKm));
    }
}
