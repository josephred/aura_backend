<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\DispatchZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parámetros de operación.
 *
 * La tabla no nace muerta: la estimación de espera y el estado de turno ya la
 * leen. Los valores por defecto reproducen exactamente el comportamiento
 * anterior, así que introducirla no cambió nada hasta que alguien los mueva.
 */
class ParametroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Parametro::olvidarCache();
    }

    private function makeService(string $id = 'medico'): ClinicalService
    {
        return ClinicalService::create([
            'id' => $id,
            'title' => 'Médico a domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Consulta general',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Sin urgencias vitales.',
            'placeholder_text' => 'Ej. fiebre y tos',
        ]);
    }

    public function test_the_migration_seeds_the_queue_parameters(): void
    {
        $this->assertDatabaseHas('parametros', ['clave' => 'cola.casos_por_profesional']);
        $this->assertDatabaseHas('parametros', ['clave' => 'cola.escalado_minutos']);

        // Por defecto, un caso por profesional: el comportamiento de siempre.
        $this->assertSame(1, Parametro::int('cola.casos_por_profesional', 99));
        $this->assertSame(15, Parametro::int('cola.escalado_minutos', 99));
        $this->assertTrue(Parametro::bool('cola.escalado_zonas_vecinas', false));
    }

    public function test_missing_or_corrupt_values_fall_back_to_the_default(): void
    {
        $this->assertSame(7, Parametro::int('cola.no_existe', 7));
        $this->assertSame('x', Parametro::texto('cola.no_existe', 'x'));
        $this->assertFalse(Parametro::bool('cola.no_existe', false));

        // Un valor que alguien dejó ilegible editando a mano no debe tumbar el
        // despacho: se ignora y manda el valor por defecto.
        Parametro::fijar('cola.roto', 'no-es-un-numero');
        $this->assertSame(42, Parametro::int('cola.roto', 42));
    }

    public function test_writing_a_parameter_invalidates_the_cache(): void
    {
        $this->assertSame(1, Parametro::int('cola.casos_por_profesional', 1));

        Parametro::fijar('cola.casos_por_profesional', 4);

        // Sin invalidación, esto seguiría devolviendo 1 durante cinco minutos y
        // quien lo editó pensaría que el panel no guarda.
        $this->assertSame(4, Parametro::int('cola.casos_por_profesional', 1));
    }

    public function test_the_wait_estimate_uses_the_capacity_parameter(): void
    {
        $this->makeService();

        $profesional = Professional::forceCreate([
            'id' => 'prof_cap',
            'name' => 'Dra. Capacidad',
            'specialty' => 'Medicina General',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => 'disponible',
            'coverage_zones' => '',
        ]);
        $profesional->services()->sync(['medico']);

        $paciente = User::create([
            'name' => 'Paciente Cap',
            'email' => 'cap@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        // Cuatro solicitudes abiertas para un solo profesional.
        foreach (range(1, 4) as $i) {
            ServiceRequest::create([
                'id' => "req_cap_$i",
                'user_id' => $paciente->id,
                'service_id' => 'medico',
                'status' => 'accepted',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234',
                'zone' => 'Providencia',
                'payment_method' => 'mercadopago',
                'payment_status' => 'approved',
                'final_price' => 25000,
                'start_time' => '10:00',
                'eta_minutes' => 30,
                'current_step' => 1,
            ]);
        }

        $dispatch = app(DispatchZoneService::class);

        $conUno = $dispatch->estimate('medico', null, 'Providencia');

        // Subiendo el tope, la misma cola cabe en menos vueltas y la espera baja.
        Parametro::fijar('cola.casos_por_profesional', 4);
        $conCuatro = $dispatch->estimate('medico', null, 'Providencia');

        $this->assertLessThan(
            $conUno['eta_min_minutes'],
            $conCuatro['eta_min_minutes'],
            'Con más casos por profesional la zona tiene más capacidad y la espera estimada debe bajar.',
        );
    }
}
