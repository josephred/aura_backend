<?php

namespace App\Services;

use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Avisar a quien puede tomar un paciente de que lo hay.
 *
 * El despacho es voluntario: la solicitud se queda en la cola hasta que alguien
 * la toma. Eso presupone que alguien está mirando la cola, y a las tres de la
 * mañana nadie tiene el portal abierto. Sin un aviso que salga del servidor, la
 * velocidad de respuesta depende de quién refresque antes.
 *
 * Vive en un solo sitio a propósito, igual que `ClinicalChannel`: el texto de
 * lo que se le dice a alguien no debe escribirse allí donde resulta cómodo.
 * Aquí están las tres razones por las que una solicitud pide profesional
 * —recién pagada, devuelta a la cola, o escalada por llevar demasiado rato— y
 * se ve de un golpe qué recibe una persona que atiende varias al día.
 */
class QueueNotifier
{
    /**
     * Cuánto tiene que pasar para volver a avisar de la misma solicitud.
     *
     * El webhook de pago reintenta, y sin esta ventana cada reintento repetía
     * el push a todos los profesionales del servicio.
     */
    private const VENTANA_MINUTOS = 5;

    public function __construct(
        private DispatchZoneService $zonas,
        private FcmService $push,
    ) {
    }

    /**
     * @param  string  $motivo  nueva | devuelta | escalada
     * @param  int  $esperando  Minutos que lleva en la cola, para el texto.
     * @return int Cuántos profesionales entraron en el aviso.
     *
     * Ojo con lo que devuelve: son los profesionales a los que se les mandó,
     * no los que lo recibieron. Un profesional sin la aplicación instalada
     * cuenta aquí y no ve nada. Confundir una cosa con la otra es cómo se
     * acaba creyendo que el aviso funciona cuando no llega a nadie.
     */
    public function announceQueued(ServiceRequest $solicitud, string $motivo, int $esperando = 0): int
    {
        // Puede haber cambiado entre que se encoló el trabajo y que corre: el
        // trabajo vive en la cola de Laravel y alguien pudo tomarla mientras
        // tanto. Avisar entonces manda a media plantilla a una solicitud que
        // ya tiene dueño.
        if (!empty($solicitud->professional_id)
            || $solicitud->status !== 'accepted'
            || $solicitud->is_scheduled) {
            return 0;
        }

        // El escalado no respeta la ventana: es un aviso distinto, con otro
        // texto y otro destinatario, y si los cortes se acortan desde la tabla
        // de parámetros tiene que poder salir igual.
        if ($motivo !== 'escalada' && $this->avisadaHacePoco($solicitud)) {
            return 0;
        }

        $candidatos = $this->candidatos($solicitud);

        if ($candidatos->isEmpty()) {
            // No es un detalle menor: significa que nadie con ese servicio está
            // libre para atender la zona. Es la señal temprana de un turno mal
            // cubierto, y sin esta línea solo se nota cuando el escalado salta
            // quince minutos después.
            Log::info('Solicitud encolada sin profesionales a quien avisar', [
                'booking_id' => $solicitud->id,
                'servicio' => $solicitud->service_id,
                'zona' => $solicitud->zone ?: 'General',
                'motivo' => $motivo,
            ]);

            $solicitud->forceFill(['cola_avisada_at' => now()])->save();

            return 0;
        }

        [$titulo, $cuerpo] = $this->texto($solicitud, $motivo, $esperando);
        $usuarios = 0;

        foreach ($candidatos as $profesional) {
            $usuarios += $this->push->notifyProfessional(
                $profesional->id,
                $titulo,
                $cuerpo,
                ['booking_id' => $solicitud->id, 'type' => 'cola', 'motivo' => $motivo],
            );
        }

        $solicitud->forceFill(['cola_avisada_at' => now()])->save();

        Log::info('Aviso de cola enviado', [
            'booking_id' => $solicitud->id,
            'motivo' => $motivo,
            'profesionales' => $candidatos->count(),
            'usuarios_alcanzados' => $usuarios,
        ]);

        return $candidatos->count();
    }

    /**
     * A quién se le ofrece.
     *
     * Solo a los que están `disponible`. `ocupado` significa "al tope de
     * atenciones simultáneas", no "tiene alguna": a quien está al tope el
     * servidor le va a responder 422 si intenta tomarla, así que avisarle es
     * ruido que no puede atender.
     *
     * @return \Illuminate\Support\Collection<int, Professional>
     */
    private function candidatos(ServiceRequest $solicitud)
    {
        $zona = $solicitud->zone ?: 'General';

        // Ya escalada: dejó de ser exclusiva de su sector, así que el aviso
        // tampoco lo es. Con `cola.escalado_zonas_vecinas` desactivado el
        // escalado no amplía a nadie —ni la visibilidad ni el aviso— y el
        // nivel 1 sirve solo para insistir con los del sector.
        if ((int) $solicitud->escalada_nivel >= 1
            && Parametro::bool('cola.escalado_zonas_vecinas', true)) {
            return Professional::query()
                ->where('active', true)
                ->where('duty_status', 'disponible')
                ->whereHas('services', fn ($q) => $q->where('clinical_services.id', $solicitud->service_id))
                ->get();
        }

        return $this->zonas->professionalsForZone($solicitud->service_id, $zona, onlyFree: true);
    }

    private function avisadaHacePoco(ServiceRequest $solicitud): bool
    {
        if (empty($solicitud->cola_avisada_at)) {
            return false;
        }

        return Carbon::parse($solicitud->cola_avisada_at)
            ->greaterThan(now()->subMinutes(self::VENTANA_MINUTOS));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function texto(ServiceRequest $solicitud, string $motivo, int $esperando): array
    {
        $servicio = ClinicalService::find($solicitud->service_id)?->short_title
            ?? $solicitud->service_id;
        $zona = $solicitud->zone ?: 'tu zona';

        return match ($motivo) {
            'devuelta' => [
                'Paciente de vuelta en la cola',
                "$servicio en $zona quedó libre otra vez. Puedes tomarlo desde la cola.",
            ],
            'escalada' => [
                "Paciente esperando hace $esperando min",
                "$servicio en $zona sigue sin profesional. Puedes tomarlo desde la cola.",
            ],
            default => [
                "Nuevo paciente en $zona",
                "$servicio en $zona está esperando. Puedes tomarlo desde la cola.",
            ],
        };
    }
}
