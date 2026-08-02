<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    /**
     * Identificador único para un mensaje del canal clínico.
     *
     * Los ids se venían armando con `prefijo_ . time()`, que tiene resolución
     * de un segundo: dos atenciones confirmadas dentro del mismo segundo
     * chocaban contra la clave primaria y la segunda reventaba con un 500. No
     * es un caso de laboratorio: le pasa a cualquier par de solicitudes
     * simultáneas, y por eso el sufijo aleatorio va aquí y no en el llamador.
     */
    public static function nextId(string $prefix): string
    {
        return $prefix . '_' . now()->timestamp . '_' . Str::lower(Str::random(6));
    }
}
