<?php

namespace App\Mail;

use App\Models\LabResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E.4 — copia del informe de laboratorio al correo registrado del paciente (REQ-17).
 *
 * Despachado asíncronamente en colas (ShouldQueue) para no bloquear la respuesta HTTP.
 */
class LabResultDelivered extends Mailable implements ShouldQueue
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
        // Adjuntar si el archivo existe y es menor a 10MB para garantizar entrega
        if ($this->result->file_path && ($this->result->file_size ?? 0) <= 10 * 1024 * 1024) {
            return [
                Attachment::fromStorageDisk('local', $this->result->file_path)
                    ->as($this->result->file_name)
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
