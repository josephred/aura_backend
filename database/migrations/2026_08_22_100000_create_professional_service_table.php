<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué servicios puede atender cada profesional.
 *
 * Este dato ya existía, pero en la peor forma posible: una constante
 * `SERVICE_SPECIALTIES` dentro de `DispatchZoneService` que comparaba subcadenas
 * contra `professionals.specialty`, un campo de texto libre. Un profesional dado
 * de alta como "Kinesiólogo" en vez de "Kinesiología" quedaba invisible para el
 * despacho: sin error, sin log, y sin más síntoma que preguntarse por qué no le
 * llegaban solicitudes. Y añadir un servicio nuevo obligaba a tocar código.
 *
 * La tabla lo vuelve explícito y consultable, que es lo que la cola de pacientes
 * por servicio necesita para saber quién puede tomar qué.
 */
return new class extends Migration
{
    /**
     * El mapeo que vivía en el código. Se usa una última vez, aquí, para que
     * ningún profesional existente pierda su asignación al migrar.
     */
    private const SERVICE_SPECIALTIES = [
        'medico' => ['medicina', 'medico'],
        'enfermeria' => ['enfermeria'],
        'kine_motora' => ['kinesiologia'],
        'kine_respiratoria' => ['kinesiologia'],
        'cuidados' => ['enfermeria', 'cuidados'],
        'laboratorio' => ['enfermeria', 'laboratorio'],
        'electrocardiograma' => ['enfermeria', 'medicina'],
        'radiologia' => ['radiologia', 'imagenologia'],
        'ambulancia' => ['ambulancia', 'paramedico'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('professional_service')) {
            Schema::create('professional_service', function (Blueprint $table) {
                $table->id();
                $table->string('professional_id');
                $table->string('service_id');
                $table->timestamps();

                // Un profesional no puede tener dos veces el mismo servicio.
                $table->unique(['professional_id', 'service_id']);

                $table->foreign('professional_id')
                    ->references('id')->on('professionals')->cascadeOnDelete();
                $table->foreign('service_id')
                    ->references('id')->on('clinical_services')->cascadeOnDelete();
            });
        }

        $this->seedFromExistingSpecialties();
    }

    /**
     * Traduce el texto libre de `specialty` a filas de la pivote.
     *
     * Solo se ejecuta si hay catálogo cargado: en una base recién migrada
     * (tests, entorno limpio) no hay nada que traducir.
     */
    private function seedFromExistingSpecialties(): void
    {
        $catalogo = DB::table('clinical_services')->pluck('id')->all();
        if ($catalogo === []) {
            return;
        }

        $profesionales = DB::table('professionals')
            ->select('id', 'specialty', 'provides_lab')
            ->get();

        $filas = [];
        $ahora = now();

        foreach ($profesionales as $profesional) {
            $texto = $this->normalize((string) ($profesional->specialty ?? ''));
            $suyos = [];

            foreach (self::SERVICE_SPECIALTIES as $serviceId => $palabras) {
                if (!in_array($serviceId, $catalogo, true)) {
                    continue;
                }

                foreach ($palabras as $palabra) {
                    if ($texto !== '' && str_contains($texto, $this->normalize($palabra))) {
                        $suyos[$serviceId] = true;
                        break;
                    }
                }
            }

            // `provides_lab` era un booleano aparte para lo mismo. Aquí pasa a
            // ser una fila más. La columna se mantiene por ahora porque
            // LabSchedulingService todavía la lee.
            if (!empty($profesional->provides_lab) && in_array('laboratorio', $catalogo, true)) {
                $suyos['laboratorio'] = true;
            }

            foreach (array_keys($suyos) as $serviceId) {
                $filas[] = [
                    'professional_id' => $profesional->id,
                    'service_id' => $serviceId,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 200) as $lote) {
            DB::table('professional_service')->insertOrIgnore($lote);
        }
    }

    private function normalize(string $value): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];

        return mb_strtolower(strtr($value, $map));
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_service');
    }
};
