<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalService extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    /** Profesionales habilitados para atender este servicio. */
    public function professionals(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Professional::class,
            'professional_service',
            'service_id',
            'professional_id',
        )->withTimestamps();
    }
}
