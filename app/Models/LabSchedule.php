<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque de disponibilidad publicado por un laboratorista para una fecha.
 *
 * Los cupos concretos no se guardan: se derivan del bloque (`start_time`,
 * `end_time`, `slot_minutes`) menos las tomas ya agendadas. Persistir cada cupo
 * obligaría a regenerarlos cada vez que el profesional corrige un horario.
 */
class LabSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'active' => 'boolean',
        'slot_minutes' => 'integer',
        'capacity' => 'integer',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
