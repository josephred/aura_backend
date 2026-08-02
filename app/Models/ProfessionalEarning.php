<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Devengo a favor de un prestador por una atención cobrada por la plataforma.
 *
 * El dinero entra completo a Aura y se dispersa después; esta fila es lo que
 * hace auditable esa promesa: cuánto se cobró, qué comisión se retuvo y cuánto
 * queda por pagar. Sin ella, "se dispersa quincenalmente" no es verificable.
 */
class ProfessionalEarning extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross_amount' => 'integer',
        'commission_bps' => 'integer',
        'commission_amount' => 'integer',
        'net_amount' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
