<?php

namespace App\Mail;

use App\Models\LabResult;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E.4 — copia del informe de laboratorio al correo registrado del paciente.
 *
 * El PDF viaja adjunto y no como enlace: un enlace a datos de salud en un
 * correo es exactamente el tipo de URL que termina reenviada. El histórico
 * descargable sigue estando en la app, detrás de la sesión del paciente.
 */
class LabResultDelivered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LabResult $result,
        public string $patientName,
        public ?string $examRequired = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Resultados de laboratorio — ' . $this->result->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.lab-result',
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->result->file_path)
                ->as($this->result->file_name)
                ->withMime('application/pdf'),
        ];
    }
}
