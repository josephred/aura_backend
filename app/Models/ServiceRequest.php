<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    /**
     * Clinical attachments are stored as private disk paths. Anything reading
     * this model over the API gets the authenticated media URL instead of a
     * raw path, so callers never need to know where the file lives.
     *
     * `assigned_professional` carries who is actually attending, so the app
     * never has to invent a name.
     */
    protected $appends = [
        'prescription_url',
        'symptom_audio_link',
        'assigned_professional',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_scheduled' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** Informes cargados por el laboratorio para esta toma de muestras. */
    public function labResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    /**
     * Identity of the professional attending this request, or null while the
     * request is still queued waiting for someone in the zone to take it.
     *
     * The app renders "asignando profesional" on null — showing a placeholder
     * name would tell the patient who is coming to their home, and be wrong.
     *
     * Incluye la ficha que el paciente tiene derecho a ver antes de que
     * llegue alguien a su casa (B.3): experiencia, registro y evaluación.
     *
     * `rating_avg` viaja como null mientras no haya evaluaciones. La columna
     * tiene 5.00 por defecto, y publicar eso sería anunciar un profesional
     * impecable del que nadie ha opinado todavía.
     */
    public function getAssignedProfessionalAttribute(): ?array
    {
        if (empty($this->professional_id)) {
            return null;
        }

        $professional = $this->professional;
        if ($professional === null) {
            return null;
        }

        $ratingCount = (int) ($professional->rating_count ?? 0);

        return [
            'id' => $professional->id,
            'name' => $professional->name,
            'specialty' => $professional->specialty,
            'phone' => $professional->phone,
            'bio' => $professional->bio,
            'photo_url' => $professional->photo_url,
            'registration_number' => $professional->registration_number,
            'years_of_experience' => $professional->years_of_experience,
            'rating_avg' => $ratingCount > 0 ? (float) $professional->rating_avg : null,
            'rating_count' => $ratingCount,
        ];
    }

    public function getPrescriptionUrlAttribute(): ?string
    {
        return $this->mediaUrl($this->prescription_file, 'prescription');
    }

    public function getSymptomAudioLinkAttribute(): ?string
    {
        return $this->mediaUrl($this->symptom_audio_url, 'symptom-audio');
    }

    /**
     * Rows created before attachments moved to the private disk still hold an
     * absolute URL; pass those through untouched.
     */
    private function mediaUrl(?string $stored, string $kind): ?string
    {
        if (empty($stored)) {
            return null;
        }

        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return $stored;
        }

        return url("/media/bookings/{$this->id}/{$kind}");
    }
}
