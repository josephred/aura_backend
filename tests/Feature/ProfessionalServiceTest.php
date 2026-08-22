<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Professional;
use App\Services\DispatchZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué servicios atiende cada profesional.
 *
 * Esto lo decidía una constante `SERVICE_SPECIALTIES` que comparaba subcadenas
 * contra `professionals.specialty`, un campo de texto libre. Quien estuviera
 * dado de alta como "Kinesiólogo" en lugar de "Kinesiología" no recibía una sola
 * solicitud, sin error ni log. Ahora la habilitación es una fila explícita, y
 * estos tests fijan que el texto ya no decide nada.
 */
class ProfessionalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(string $id, string $title): ClinicalService
    {
        return ClinicalService::create([
            'id' => $id,
            'title' => $title,
            'short_title' => $title,
            'subtitle' => 'Prestación de prueba',
            'description' => 'Prestación de prueba',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Sin urgencias vitales.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    private function makeProfessional(string $id, string $specialty, string $zones = ''): Professional
    {
        return Professional::forceCreate([
            'id' => $id,
            'name' => "Prof $id",
            'specialty' => $specialty,
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => 'disponible',
            'coverage_zones' => $zones,
        ]);
    }

    public function test_the_pivot_decides_who_is_dispatched_not_the_specialty_text(): void
    {
        $this->makeService('medico', 'Médico a domicilio');
        $this->makeService('kine_motora', 'Kinesiología motora');

        // Su texto dice "Medicina General", pero solo está habilitado en
        // kinesiología. Antes el texto mandaba; ahora manda la fila.
        $kine = $this->makeProfessional('prof_kine', 'Medicina General');
        $kine->services()->sync(['kine_motora']);

        // Y al revés: el texto no menciona medicina por ninguna parte.
        $medico = $this->makeProfessional('prof_medico', 'Profesional de la salud');
        $medico->services()->sync(['medico']);

        $dispatch = app(DispatchZoneService::class);

        $paraMedico = $dispatch->professionalsForZone('medico', 'Providencia')->pluck('id')->all();
        $this->assertSame(['prof_medico'], $paraMedico);

        $paraKine = $dispatch->professionalsForZone('kine_motora', 'Providencia')->pluck('id')->all();
        $this->assertSame(['prof_kine'], $paraKine);
    }

    public function test_a_professional_without_services_is_dispatched_nothing(): void
    {
        $this->makeService('medico', 'Médico a domicilio');

        // Texto impecable, cero filas en la pivote.
        $this->makeProfessional('prof_huerfano', 'Medicina Interna');

        $encontrados = app(DispatchZoneService::class)
            ->professionalsForZone('medico', 'Providencia');

        $this->assertCount(0, $encontrados);
    }

    public function test_zone_coverage_still_applies_on_top_of_the_service(): void
    {
        $this->makeService('medico', 'Médico a domicilio');

        $providencia = $this->makeProfessional('prof_prov', 'Medicina General', 'Providencia, Ñuñoa');
        $providencia->services()->sync(['medico']);

        $rancagua = $this->makeProfessional('prof_ranca', 'Medicina General', 'Rancagua');
        $rancagua->services()->sync(['medico']);

        $encontrados = app(DispatchZoneService::class)
            ->professionalsForZone('medico', 'Providencia')
            ->pluck('id')
            ->all();

        $this->assertSame(['prof_prov'], $encontrados);
    }

    public function test_off_duty_professionals_are_left_out(): void
    {
        $this->makeService('medico', 'Médico a domicilio');

        $activo = $this->makeProfessional('prof_activo', 'Medicina General');
        $activo->services()->sync(['medico']);

        $desconectado = $this->makeProfessional('prof_off', 'Medicina General');
        $desconectado->forceFill(['duty_status' => 'desconectado'])->save();
        $desconectado->services()->sync(['medico']);

        $encontrados = app(DispatchZoneService::class)
            ->professionalsForZone('medico', 'Providencia')
            ->pluck('id')
            ->all();

        $this->assertSame(['prof_activo'], $encontrados);
    }

    public function test_attends_answers_for_a_single_service(): void
    {
        $this->makeService('medico', 'Médico a domicilio');
        $this->makeService('enfermeria', 'Enfermería');

        $prof = $this->makeProfessional('prof_mixto', 'Enfermería');
        $prof->services()->sync(['enfermeria']);

        $this->assertTrue($prof->attends('enfermeria'));
        $this->assertFalse($prof->attends('medico'));
    }

    public function test_staff_account_command_assigns_and_validates_services(): void
    {
        $this->makeService('medico', 'Médico a domicilio');
        $prof = $this->makeProfessional('prof_cmd', 'Medicina General');

        $this->artisan('staff:account', [
            'email' => 'cmd@aura.cl',
            '--professional' => 'prof_cmd',
            '--password' => 'clave-segura-123',
            '--services' => 'medico',
        ])->assertExitCode(0);

        $this->assertTrue($prof->fresh()->attends('medico'));

        // Un id que no está en el catálogo se rechaza en vez de crear una
        // habilitación fantasma.
        $this->artisan('staff:account', [
            'email' => 'cmd@aura.cl',
            '--professional' => 'prof_cmd',
            '--password' => 'clave-segura-123',
            '--services' => 'servicio_inexistente',
        ])->assertExitCode(1);
    }
}
