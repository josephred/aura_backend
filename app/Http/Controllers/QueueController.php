<?php

namespace App\Http\Controllers;

use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\Dependent;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ClinicalChannel;
use App\Services\DispatchZoneService;
use Illuminate\Http\JsonResponse;

/**
 * Cola de pacientes por servicio.
 *
 * Tomar una atención pasa a ser un acto explícito. Antes ocurría como efecto
 * colateral: `claimIfUnassigned` se disparaba al avanzar el estado, al escribir
 * en el chat y al transmitir la posición GPS — y el portal transmite la
 * posición en cuanto seleccionas una tarjeta, así que **mirar** una solicitud de
 * la cola bastaba para quedártela sin haberlo decidido.
 */
class QueueController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesStaffScope;

    /** Estados en los que una atención sigue ocupando al profesional. */
    private const ABIERTAS = ['accepted', 'en_camino', 'en_atencion'];

    /** @var array<string, bool> Cobertura por servicio|zona, dentro de una petición. */
    private array $coberturaPorZona = [];

    /**
     * La cola que este profesional puede tomar, agrupada por servicio.
     *
     * El orden lo fija el negocio: primero la zona propia, y dentro de cada
     * grupo por antigüedad. `esperando_minutos` viaja calculado para que la
     * interfaz no tenga que hacer aritmética de fechas y para que se vea de un
     * golpe quién lleva más rato esperando.
     */
    public function index(DispatchZoneService $zones): JsonResponse
    {
        $profesional = $this->profesionalActual();
        $tope = max(1, Parametro::int('cola.casos_por_profesional', 1));

        // Un admin coordina y ve el catálogo entero; un profesional solo aquello
        // que tiene habilitado en `professional_service`.
        $serviceIds = $profesional
            ? $profesional->services()->pluck('clinical_services.id')->all()
            : ClinicalService::pluck('id')->all();

        $abiertas = $profesional ? $this->casosAbiertos($profesional->id) : 0;

        if ($serviceIds === []) {
            return response()->json([
                'professional_id' => $profesional?->id,
                'casos_abiertos' => $abiertas,
                'tope' => $tope,
                'servicios' => [],
                // Sin esto el profesional ve una cola vacía y concluye que no
                // hay pacientes, cuando lo que pasa es que nadie le habilitó
                // ningún servicio.
                'aviso' => 'No tienes servicios habilitados. Pídele a administración que te asigne los que atiendes.',
            ]);
        }

        $pendientes = ServiceRequest::query()
            ->whereIn('service_id', $serviceIds)
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', ''))
            ->where('status', 'accepted')
            ->where('is_scheduled', false)
            ->orderBy('created_at')
            ->get();

        $titulos = ClinicalService::whereIn('id', $serviceIds)->pluck('short_title', 'id');
        $servicios = [];

        foreach ($serviceIds as $serviceId) {
            $delServicio = $pendientes->where('service_id', $serviceId);

            $enMiZona = [];
            $fueraDeZona = [];

            foreach ($delServicio as $solicitud) {
                // Sin ficha de profesional (un admin mirando) todo cuenta como
                // propio: no tiene zona con la que comparar.
                $propia = $profesional === null || $zones->covers($profesional, $solicitud->zone);

                if ($propia) {
                    $enMiZona[] = $this->fila($solicitud);
                    continue;
                }

                // Una solicitud de otro sector solo se ofrece cuando ya escaló,
                // o cuando esa zona no la cubre nadie. Sin esta condición "que
                // mande la zona" no significaba nada: todas las solicitudes de
                // la ciudad le aparecían a todo el mundo desde el minuto cero,
                // y quien refrescara primero se las llevaba, que es justo lo
                // contrario de repartir por sector.
                if ((int) $solicitud->escalada_nivel < 1
                    && $this->alguienCubre($solicitud, $zones)) {
                    continue;
                }

                $fueraDeZona[] = $this->fila($solicitud);
            }

            if ($enMiZona === [] && $fueraDeZona === []) {
                continue;
            }

            $servicios[] = [
                'service_id' => $serviceId,
                'titulo' => $titulos[$serviceId] ?? $serviceId,
                'esperando' => count($enMiZona) + count($fueraDeZona),
                'en_mi_zona' => $enMiZona,
                'fuera_de_zona' => $fueraDeZona,
            ];
        }

        // Los servicios con gente esperando más rato, arriba.
        usort($servicios, fn ($a, $b) => $b['esperando'] <=> $a['esperando']);

        return response()->json([
            'professional_id' => $profesional?->id,
            'casos_abiertos' => $abiertas,
            'tope' => $tope,
            'servicios' => $servicios,
        ]);
    }

    /**
     * Tomar una solicitud de la cola.
     */
    public function claim(string $id, ClinicalChannel $canal): JsonResponse
    {
        $profesional = $this->profesionalActual();

        if (!$profesional) {
            return response()->json([
                'error' => 'Las atenciones las toman los profesionales, no las cuentas de coordinación.',
            ], 403);
        }

        if ($profesional->duty_status === 'desconectado') {
            return response()->json([
                'error' => 'Estás fuera de turno. Actívalo para tomar solicitudes.',
            ], 403);
        }

        $solicitud = ServiceRequest::where('id', $id)
            ->where('status', 'accepted')
            ->where('is_scheduled', false)
            ->first();

        if (!$solicitud) {
            return response()->json(['error' => 'Solicitud no encontrada o ya no está en la cola.'], 404);
        }

        if (!$profesional->attends($solicitud->service_id)) {
            return response()->json([
                'error' => 'No tienes habilitado este servicio.',
            ], 403);
        }

        $tope = max(1, Parametro::int('cola.casos_por_profesional', 1));
        $abiertas = $this->casosAbiertos($profesional->id);

        if ($abiertas >= $tope) {
            return response()->json([
                'error' => "Ya llevas $abiertas " . ($abiertas === 1 ? 'atención abierta' : 'atenciones abiertas')
                    . ". Cierra alguna para tomar otra.",
            ], 422);
        }

        // La toma es un UPDATE condicionado a que siga libre, y se mira cuántas
        // filas cambiaron. Con la cola refrescándose cada pocos segundos, que
        // dos profesionales pulsen a la vez no es el caso raro sino el normal:
        // leer y escribir después dejaba que el segundo pisara al primero en
        // silencio, y el paciente quedaba asignado a alguien que ya no iba.
        $tomadas = ServiceRequest::where('id', $solicitud->id)
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', ''))
            ->update(['professional_id' => $profesional->id]);

        if ($tomadas === 0) {
            return response()->json([
                'error' => 'Otro profesional la tomó hace un momento.',
            ], 409);
        }

        $solicitud->refresh();
        $canal->announceAssignment($solicitud, $this->staffDisplayName());
        $this->sincronizarTurno($profesional);

        return response()->json([
            'success' => true,
            'booking' => $solicitud,
            'casos_abiertos' => $this->casosAbiertos($profesional->id),
            'tope' => $tope,
        ]);
    }

    /**
     * Devolver una solicitud a la cola.
     *
     * Sin esto, un clic equivocado deja al paciente esperando a alguien que no
     * va a ir y a nadie más le aparece la solicitud para tomarla.
     */
    public function release(string $id, ClinicalChannel $canal): JsonResponse
    {
        $profesional = $this->profesionalActual();

        if (!$profesional) {
            return response()->json(['error' => 'Sin ficha de profesional asociada.'], 403);
        }

        $solicitud = ServiceRequest::where('id', $id)
            ->where('professional_id', $profesional->id)
            ->first();

        if (!$solicitud) {
            return response()->json(['error' => 'Esta solicitud no está asignada a ti.'], 404);
        }

        // Una atención ya empezada no se suelta: el profesional está en el
        // domicilio del paciente. Se cancela o se cierra, que son actos
        // distintos y quedan registrados como tales.
        if (!in_array($solicitud->status, ['accepted', 'en_camino'], true)) {
            return response()->json([
                'error' => 'La atención ya está en curso. Ciérrala o cancélala en vez de soltarla.',
            ], 422);
        }

        $solicitud->update([
            'professional_id' => null,
            'status' => 'accepted',
            'current_step' => 1,
            // La posición del profesional que la soltó deja de tener sentido.
            'professional_lat' => null,
            'professional_lng' => null,
            'professional_location_updated_at' => null,
        ]);

        $solicitud->refresh();
        $canal->announceRelease($solicitud);
        $this->sincronizarTurno($profesional);

        \Illuminate\Support\Facades\Log::info('Solicitud devuelta a la cola', [
            'booking_id' => $solicitud->id,
            'professional_id' => $profesional->id,
        ]);

        return response()->json([
            'success' => true,
            'casos_abiertos' => $this->casosAbiertos($profesional->id),
        ]);
    }

    /**
     * Una solicitud de la cola, tal como la pinta la interfaz.
     *
     * @return array<string, mixed>
     */
    private function fila(ServiceRequest $solicitud): array
    {
        $nombre = User::find($solicitud->user_id)?->name ?? 'Paciente';

        if ($solicitud->patient_type === 'dependent' && $solicitud->dependent_id) {
            $dependiente = Dependent::find($solicitud->dependent_id);
            if ($dependiente) {
                $nombre = "{$dependiente->name} ({$dependiente->relationship})";
            }
        }

        return [
            'id' => $solicitud->id,
            'paciente' => $nombre,
            'direccion' => $solicitud->address_text,
            'destino' => $solicitud->destination_address,
            'zona' => $solicitud->zone ?: 'General',
            'sintomas' => $solicitud->symptoms_description,
            'examen' => $solicitud->exam_required,
            'precio' => (int) $solicitud->final_price,
            'nota_de_voz' => $solicitud->symptom_audio_link,
            'orden_medica' => $solicitud->prescription_url,
            'solicitada' => $solicitud->start_time,
            // Calculado aquí y no en el cliente: es el dato que decide a quién
            // se atiende primero y no puede depender del reloj del navegador.
            'esperando_minutos' => $solicitud->created_at
                ? (int) $solicitud->created_at->diffInMinutes(now())
                : 0,
            'escalada' => (int) $solicitud->escalada_nivel > 0,
            // 1 = ofrecida fuera de su sector. 2 = operaciones ya la tiene
            // marcada. La interfaz distingue: no es lo mismo "nadie del sector
            // respondió" que "esto lleva media hora sin que vaya nadie".
            'escalada_nivel' => (int) $solicitud->escalada_nivel,
        ];
    }

    /**
     * ¿Hay algún profesional en turno que cubra la zona de esta solicitud?
     *
     * Se memoiza por servicio y zona: la bandeja se refresca cada pocos
     * segundos y sin esto sería una consulta por cada tarjeta en pantalla.
     *
     * @param array<string, bool> $memo
     */
    private function alguienCubre(ServiceRequest $solicitud, DispatchZoneService $zones): bool
    {
        $zona = $solicitud->zone ?: 'General';
        $clave = $solicitud->service_id . '|' . $zona;

        if (!array_key_exists($clave, $this->coberturaPorZona)) {
            $this->coberturaPorZona[$clave] = $zones
                ->professionalsForZone($solicitud->service_id, $zona)
                ->isNotEmpty();
        }

        return $this->coberturaPorZona[$clave];
    }

    private function profesionalActual(): ?Professional
    {
        $id = $this->scopedProfessionalId();

        return $id ? Professional::find($id) : null;
    }

    private function casosAbiertos(string $professionalId): int
    {
        return ServiceRequest::where('professional_id', $professionalId)
            ->whereIn('status', self::ABIERTAS)
            ->count();
    }

    /**
     * 'ocupado' significa "al tope de atenciones", no "tiene alguna".
     */
    private function sincronizarTurno(Professional $profesional): void
    {
        if ($profesional->duty_status === 'desconectado') {
            return;
        }

        $tope = max(1, Parametro::int('cola.casos_por_profesional', 1));
        $siguiente = $this->casosAbiertos($profesional->id) >= $tope ? 'ocupado' : 'disponible';

        if ($profesional->duty_status !== $siguiente) {
            $profesional->forceFill(['duty_status' => $siguiente])->save();
        }
    }
}
