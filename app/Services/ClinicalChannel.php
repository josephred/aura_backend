<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ClinicalService;
use App\Models\ServiceRequest;

/**
 * Lo que el canal clínico le dice al paciente.
 *
 * Vive en un solo sitio a propósito. Los mensajes automáticos de este proyecto
 * han sido una fuente repetida de problemas —un bot de palabras clave que
 * afirmaba que el pago estaba procesado sin comprobarlo, dos mensajes sembrados
 * en el cliente firmados por un profesional inexistente, y una presentación que
 * el servidor escribía al activar la reserva diciendo "me dirijo hacia tu
 * ubicación" cuando nadie la había tomado— y todos tenían la misma causa: el
 * texto se escribía allí donde resultaba cómodo, sin que nadie tuviera delante
 * el conjunto de lo que el paciente acaba leyendo.
 */
class ClinicalChannel
{
    /**
     * Presentación del profesional que acaba de tomar la atención.
     *
     * Va firmada: es el primer mensaje que el paciente recibe de una persona
     * real y tiene que saber de quién.
     */
    public function announceAssignment(ServiceRequest $serviceRequest, ?string $staffName): void
    {
        $servicio = $this->serviceTitle($serviceRequest);

        $texto = $staffName
            ? "$staffName tomó tu atención de $servicio."
            : "Un profesional tomó tu solicitud de $servicio.";

        // Va como 'system' y en tercera persona, no firmado como el profesional.
        //
        // Aquí hubo un mensaje en primera persona —"Hola, soy X y voy a
        // atenderte"— que nadie había tecleado, y quitarlo fue correcto: poner
        // palabras en boca de una persona real es fingir. Pero quitarlo sin
        // dejar nada convirtió el hilo en un sitio donde no consta lo que pasa:
        // el profesional podía tomar tu caso, salir y llegar a tu puerta, y el
        // último mensaje seguía diciendo que tu solicitud estaba en la cola.
        // Un hecho anotado por el sistema no es una voz prestada, y es lo que
        // el contador de no leídos puede contar.
        ChatMessage::create([
            'id' => ChatMessage::nextId('msg_claim'),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => $texto,
            'timestamp' => date('H:i'),
        ]);

        $this->notify($serviceRequest, 'Un profesional tomó tu atención', $texto);
    }

    /**
     * El profesional soltó la atención y vuelve a la cola.
     *
     * Se le dice al paciente. Enterarse de que quien iba a atenderte ya no va
     * es peor por silencio que por aviso, y sin esto el único síntoma sería que
     * el seguimiento deja de avanzar sin explicación.
     */
    public function announceRelease(ServiceRequest $serviceRequest): void
    {
        $servicio = $this->serviceTitle($serviceRequest);

        $texto = "El profesional asignado no podrá tomar tu solicitud de $servicio. "
            . 'Vuelve a la cola de tu sector y será asignada al siguiente profesional en turno.';

        ChatMessage::create([
            'id' => ChatMessage::nextId('msg_release'),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => $texto,
            'timestamp' => date('H:i'),
        ]);

        $this->notify($serviceRequest, 'Tu solicitud volvió a la cola', $texto);
    }

    /**
     * Nadie ha tomado la solicitud y ya pasó demasiado rato.
     *
     * Solo se escribe en el segundo nivel de escalado, no en el primero. A los
     * quince minutos no ha cambiado nada para el paciente y decirle "todavía
     * nadie te ha tomado" es alarma sin nada que hacer con ella. En el segundo
     * nivel sí cambió algo: hay una persona de operaciones mirando su caso, y
     * el paciente merece saber que puede cancelar en vez de seguir esperando
     * a ciegas. El silencio ahí no es prudencia, es dejarlo sin opciones.
     */
    public function announceStillSearching(ServiceRequest $serviceRequest): void
    {
        $servicio = $this->serviceTitle($serviceRequest);

        $texto = "Seguimos buscando un profesional disponible para tu solicitud de $servicio. "
            . 'La ampliamos a los sectores vecinos y nuestro equipo está al tanto. '
            . 'Si prefieres no seguir esperando, puedes cancelarla desde la aplicación.';

        ChatMessage::create([
            'id' => ChatMessage::nextId('msg_escala'),
            'service_request_id' => $serviceRequest->id,
            'sender' => 'system',
            'text' => $texto,
            'timestamp' => date('H:i'),
        ]);

        $this->notify($serviceRequest, 'Seguimos buscando profesional', $texto);
    }

    private function serviceTitle(ServiceRequest $serviceRequest): string
    {
        return ClinicalService::find($serviceRequest->service_id)?->short_title ?? 'tu atención';
    }

    private function notify(ServiceRequest $serviceRequest, string $titulo, string $cuerpo): void
    {
        app(FcmService::class)->notifyUser(
            $serviceRequest->user_id,
            $titulo,
            $cuerpo,
            ['booking_id' => $serviceRequest->id, 'type' => 'chat'],
        );
    }
}
