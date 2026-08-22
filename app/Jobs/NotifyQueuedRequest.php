<?php

namespace App\Jobs;

use App\Models\ServiceRequest;
use App\Services\QueueNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisar a los profesionales, fuera del camino de la petición.
 *
 * El disparo natural es el callback de pago de Mercado Pago. Mandar los push
 * ahí dentro significaría que la confirmación del pago espera a FCM: con diez
 * profesionales y varios dispositivos cada uno son decenas de llamadas HTTP,
 * cada una con su tiempo de espera. Un FCM lento retrasaría la confirmación, y
 * uno caído la haría fallar —el paciente pagó y la reserva no se activa por un
 * push. Aquí el peor caso es que el aviso llegue tarde.
 *
 * Se serializa el id y no el modelo a propósito: entre que esto se encola y
 * corre, la solicitud pudo ser tomada o cancelada, y lo que importa es su
 * estado al ejecutarse, no una foto de hace un rato. `QueueNotifier` vuelve a
 * comprobarlo antes de mandar nada.
 */
class NotifyQueuedRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $serviceRequestId,
        public string $motivo = 'nueva',
    ) {
    }

    public function handle(QueueNotifier $avisos): void
    {
        $solicitud = ServiceRequest::find($this->serviceRequestId);

        if (!$solicitud) {
            return;
        }

        $avisos->announceQueued($solicitud, $this->motivo);
    }
}
