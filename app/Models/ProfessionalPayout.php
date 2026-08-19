<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Liquidación/Dispersión formal a un prestador de salud.
 */
class ProfessionalPayout extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'gross_total' => 'integer',
        'retained_total' => 'integer',
        'net_total' => 'integer',
        'services_count' => 'integer',
        'paid_at' => 'datetime',
        'bank_snapshot' => 'array',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(ProfessionalEarning::class, 'payout_id');
    }
}
