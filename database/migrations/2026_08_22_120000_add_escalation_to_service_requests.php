<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro del escalado de una solicitud que nadie toma.
 *
 * Sin estas dos columnas el escalado no puede existir sin repetirse: el comando
 * corre cada minuto, y sin saber qué ya se avisó, una solicitud olvidada
 * dispararía la misma notificación sesenta veces por hora a todos los
 * profesionales del servicio. `escalada_nivel` es lo que hace que cada aviso
 * ocurra una sola vez, y solo sube.
 *
 * Niveles:
 *   0  nadie la ha tomado todavía, pero está dentro del tiempo normal de espera
 *   1  pasó `cola.escalado_minutos`: se ofrece fuera de su zona y se avisa
 *   2  pasó `cola.avisar_operaciones_minutos`: operaciones tiene que mirarla
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Cuándo escaló por última vez. Es el dato que contesta "¿cuánto
            // lleva esto sin que nadie intervenga?" en una revisión posterior;
            // `created_at` solo dice cuándo se pidió.
            $table->timestamp('escalada_at')->nullable();
            $table->unsignedTinyInteger('escalada_nivel')->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['escalada_at', 'escalada_nivel']);
        });
    }
};
