<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Professional extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    // Never expose account credentials or internal staffing data through API
    // serialization: /api/professionals is a public catalogue, so shift status
    // and coverage must not leak to patients.
    // `phone` is reachable through ServiceRequest::assigned_professional, which
    // only the patient being attended can read. It must not travel in the
    // public /api/professionals catalogue.
    // `commission_bps` es información comercial del contrato con el prestador:
    // no tiene por qué viajar en el catálogo que ve el paciente.
    protected $hidden = [
        'email', 'password', 'role', 'last_login_at',
        'duty_status', 'coverage_zones', 'phone', 'commission_bps',
    ];

    protected $casts = [
        'active' => 'boolean',
        'provides_lab' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /** Bloques de toma de muestras publicados por este prestador. */
    public function labSchedules(): HasMany
    {
        return $this->hasMany(LabSchedule::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProfessionalSchedule::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
