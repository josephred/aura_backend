<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dependent extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'age' => 'integer',
        'last_vaccine_alert_milestone' => 'integer',
        'last_vaccine_alert_sent_at' => 'datetime',
    ];

    protected $appends = [
        'age_months',
    ];

    /**
     * Edad precisa en meses calculada dinámicamente desde birth_date.
     */
    public function getAgeMonthsAttribute(): ?int
    {
        if ($this->birth_date) {
            return (int) abs(now()->diffInMonths($this->birth_date));
        }

        return $this->age !== null ? ((int) $this->age * 12) : null;
    }
}
