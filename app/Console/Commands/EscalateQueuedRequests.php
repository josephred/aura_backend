<?php

namespace App\Console\Commands;

use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Services\ClinicalChannel;
use App\Services\DispatchZoneService;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Escala las solicitudes que nadie toma.
 *
 * El despacho es voluntario: la solicitud queda en la cola de su sector y algún
 * profesional en turno la toma. Eso funciona mientras haya alguien mirando. Si
 * no lo hay —turno vacío, comuna sin cobertura, todos al tope— la solicitud se
 * queda ahí en silencio y el paciente espera sin que nadie se entere. Este
 * comando es lo que convierte ese silencio en algo que ocurre:
 *
 *   Nivel 1, a los `cola.escalado_minutos`: deja de ser exclusiva de su sector
 *   y se ofrece también fuera de él, y se avisa por push a los profesionales
 *   del servicio. Al paciente no se le dice nada: para él no ha cambiado nada
 *   y avisarle sería alarma sin nada que hacer con ella.
 *
 *   Nivel 2, a los `cola.avisar_operaciones_minutos`: queda marcada para el
 *   panel de operaciones, con un log de aviso, y al paciente sí se le habla:
 *   ahí ya hay una persona mirando su caso y merece saber que puede cancelar
 *   en vez de seguir esperando a ciegas.
 *
 * `escalada_nivel` solo sube, y cada nivel actúa una sola vez. Sin eso, con el
 * comando corriendo cada minuto, una solicitud olvidada dispararía el mismo
 * aviso sesenta veces por hora a todos los profesionales del servicio.
 */
class EscalateQueuedRequests extends Command
{
    protected $signature = 'cola:escalar
                            {--dry-run : Muestra lo que haría sin escribir nada}';

    protected $description = 'Escala las solicitudes de la cola que nadie ha tomado';

    public function handle(
        DispatchZoneService $zonas,
        ClinicalChannel $canal,
        FcmService $push,
    ): int {
        $simulacion = (bool) $this->option('dry-run');

        $minutosNivel1 = max(1, Parametro::int('cola.escalado_minutos', 15));
        $minutosNivel2 = max($minutosNivel1, Parametro::int('cola.avisar_operaciones_minutos', 30));

        // Solo lo que está de verdad en la cola. Las agendadas tienen hora
        // convenida y esperar no es un síntoma; las ya tomadas tienen dueño.
        $enCola = ServiceRequest::query()
            ->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', ''))
            ->where('status', 'accepted')
            ->where('is_scheduled', false)
            ->where('escalada_nivel', '<', 2)
            ->orderBy('created_at')
            ->get();

        $nivel1 = 0;
        $nivel2 = 0;

        foreach ($enCola as $solicitud) {
            $esperando = $solicitud->created_at
                ? (int) $solicitud->created_at->diffInMinutes(now())
                : 0;

            $nivelActual = (int) $solicitud->escalada_nivel;
            $nivelQueToca = match (true) {
                $esperando >= $minutosNivel2 => 2,
                $esperando >= $minutosNivel1 => 1,
                default => 0,
            };

            if ($nivelQueToca <= $nivelActual) {
                continue;
            }

            $servicio = ClinicalService::find($solicitud->service_id)?->short_title
                ?? $solicitud->service_id;
            $zona = $solicitud->zone ?: 'General';

            if ($simulacion) {
                $this->line("  [simulacion] {$solicitud->id} ($servicio, $zona) "
                    . "espera $esperando min -> nivel $nivelQueToca");
                if ($nivelActual < 1) {
                    $nivel1++;
                }
                if ($nivelQueToca >= 2) {
                    $nivel2++;
                }
                continue;
            }

            $solicitud->forceFill([
                'escalada_nivel' => $nivelQueToca,
                'escalada_at' => now(),
            ])->save();

            // Los niveles se recorren, no se saltan. Una solicitud puede pasar
            // de 0 a 2 de una vez —el primer arranque tras desplegar, o el rato
            // en que el scheduler estuvo caido— y entonces nadie le habria
            // avisado a los profesionales, que es justo el paso que sirve para
            // que alguien la tome. A los cuarenta minutos sigue siendo cierto
            // que hay que ofrecerla fuera de su sector.
            if ($nivelActual < 1) {
                $nivel1++;
                $avisados = $this->avisarProfesionales($solicitud, $servicio, $zona, $esperando, $zonas, $push);

                Log::info('Solicitud escalada a nivel 1', [
                    'booking_id' => $solicitud->id,
                    'servicio' => $solicitud->service_id,
                    'zona' => $zona,
                    'esperando_minutos' => $esperando,
                    'profesionales_avisados' => $avisados,
                ]);

                $this->line("  {$solicitud->id} ($servicio, $zona): $esperando min, "
                    . "ampliada fuera de zona, $avisados avisados");
            }

            if ($nivelQueToca < 2) {
                continue;
            }

            $nivel2++;
            $canal->announceStillSearching($solicitud);

            // Warning y no info: esto es una solicitud pagada que lleva media
            // hora sin que nadie vaya. Si el log de produccion se filtra por
            // nivel, esta linea tiene que aparecer.
            Log::warning('Solicitud sin tomar: requiere operaciones', [
                'booking_id' => $solicitud->id,
                'servicio' => $solicitud->service_id,
                'zona' => $zona,
                'esperando_minutos' => $esperando,
                'profesionales_del_servicio_en_turno' => Professional::query()
                    ->where('active', true)
                    ->whereIn('duty_status', ['disponible', 'ocupado'])
                    ->whereHas('services', fn ($q) => $q->where('clinical_services.id', $solicitud->service_id))
                    ->count(),
            ]);

            $this->warn("  {$solicitud->id} ($servicio, $zona): $esperando min sin tomar, avisado a operaciones");
        }

        $prefijo = $simulacion ? '[simulacion] ' : '';
        $this->info("{$prefijo}Escaladas: $nivel1 a nivel 1, $nivel2 a nivel 2 "
            . "(cortes: $minutosNivel1 y $minutosNivel2 min)");

        return self::SUCCESS;
    }

    /**
     * Avisa a quien puede tomarla ahora que dejó de ser exclusiva de su sector.
     *
     * Se avisa a los profesionales del servicio que están en turno. Con
     * `cola.escalado_zonas_vecinas` desactivado el aviso se limita a los que ya
     * cubrían la zona, que es a quienes ya les aparecía: sirve para insistir,
     * no para ampliar.
     */
    private function avisarProfesionales(
        ServiceRequest $solicitud,
        string $servicio,
        string $zona,
        int $esperando,
        DispatchZoneService $zonas,
        FcmService $push,
    ): int {
        $ampliar = Parametro::bool('cola.escalado_zonas_vecinas', true);

        $candidatos = $ampliar
            ? Professional::query()
                ->where('active', true)
                ->whereIn('duty_status', ['disponible', 'ocupado'])
                ->whereHas('services', fn ($q) => $q->where('clinical_services.id', $solicitud->service_id))
                ->get()
            : $zonas->professionalsForZone($solicitud->service_id, $zona);

        $avisados = 0;

        foreach ($candidatos as $profesional) {
            $avisados += $push->notifyProfessional(
                $profesional->id,
                "Paciente esperando hace $esperando min",
                "$servicio en $zona sigue sin profesional. Puedes tomarlo desde la cola.",
                ['booking_id' => $solicitud->id, 'type' => 'cola_escalada'],
            );
        }

        return $avisados;
    }
}
