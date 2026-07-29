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
    protected $hidden = [
        'email', 'password', 'role', 'last_login_at',
        'duty_status', 'coverage_zones', 'phone',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(ProfessionalSchedule::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
