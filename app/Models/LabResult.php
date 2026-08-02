<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informe de laboratorio cargado por el prestador.
 *
 * El archivo vive en el disco privado; lo que sale por la API es siempre el
 * enlace autenticado, nunca la ruta de almacenamiento.
 */
class LabResult extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $hidden = ['file_path'];

    protected $appends = ['download_url'];

    protected $casts = [
        'issued_at' => 'datetime',
        'emailed_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function getDownloadUrlAttribute(): string
    {
        return url("/media/lab-results/{$this->id}");
    }
}
