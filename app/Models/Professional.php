<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Professional extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    // Never expose account credentials through API serialization
    protected $hidden = ['email', 'password', 'role', 'last_login_at'];

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
