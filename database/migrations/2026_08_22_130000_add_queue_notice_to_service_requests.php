<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se avisó por última vez a los profesionales de que esta solicitud
 * está esperando.
 *
 * Existe por una razón concreta: el webhook de pago reintenta. Mercado Pago
 * puede llamar dos o tres veces a la misma confirmación, y cada llamada pasa
 * por `activateBooking`. Sin una marca en la fila, cada reintento repetiría el
 * push a todos los profesionales del servicio, que es la manera más rápida de
 * que la gente silencie las notificaciones de la aplicación —y entonces el
 * aviso deja de servir justo cuando hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('cola_avisada_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('cola_avisada_at');
        });
    }
};
