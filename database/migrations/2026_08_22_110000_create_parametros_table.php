<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros de operación, editables sin desplegar.
 *
 * Distintos de `config/aura.php`: allí viven los valores de despliegue —la
 * comisión, el recargo, las ventanas de laboratorio—, que cambian con una
 * decisión de negocio y un release. Aquí van los números que operaciones va a
 * querer mover mirando la realidad de un martes cualquiera, sin esperar a nadie.
 *
 * Mezclarlos convertiría esto en un cajón de sastre, así que la regla es
 * sencilla: si para cambiarlo hace falta pensar en el contrato con el
 * prestador, va en config; si hace falta mirar la cola, va aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('parametros')) {
            Schema::create('parametros', function (Blueprint $table) {
                $table->string('clave')->primary();
                $table->text('valor');
                $table->string('tipo')->default('int');   // int | bool | string | json
                $table->string('grupo')->default('general');
                // En castellano y dirigida a quien lo va a editar, que no es
                // necesariamente quien escribió el código.
                $table->string('descripcion');
                $table->timestamps();
            });
        }

        $ahora = now();

        // insertOrIgnore y no updateOrCreate: si alguien ya ajustó un valor
        // desde el panel, volver a correr las migraciones no debe pisárselo.
        DB::table('parametros')->insertOrIgnore([
            [
                'clave' => 'cola.casos_por_profesional',
                'valor' => '1',
                'tipo' => 'int',
                'grupo' => 'cola',
                'descripcion' => 'Cuántas atenciones abiertas puede llevar un profesional a la vez. '
                    . 'En 1 se comporta como hasta ahora; subirlo habilita que tome varias.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'cola.escalado_minutos',
                'valor' => '15',
                'tipo' => 'int',
                'grupo' => 'cola',
                'descripcion' => 'Minutos que una solicitud puede esperar sin que nadie la tome '
                    . 'antes de ampliarla a zonas vecinas.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'cola.escalado_zonas_vecinas',
                'valor' => 'true',
                'tipo' => 'bool',
                'grupo' => 'cola',
                'descripcion' => 'Si al escalar una solicitud se ofrece también a profesionales '
                    . 'de sectores contiguos.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'cola.avisar_operaciones_minutos',
                'valor' => '30',
                'tipo' => 'int',
                'grupo' => 'cola',
                'descripcion' => 'Minutos sin asignar tras los cuales la solicitud se marca en el '
                    . 'panel de operaciones para que alguien intervenga a mano.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
    }
};
