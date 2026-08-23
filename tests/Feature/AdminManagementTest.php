<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeServices(): void
    {
        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Médico a domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Servicio médico',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Servicio no apto para urgencias.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);

        ClinicalService::create([
            'id' => 'kine_motora',
            'title' => 'Kinesiología Motora',
            'short_title' => 'Kinesiología',
            'subtitle' => 'Rehabilitación motora',
            'description' => 'Servicio kinesiológico',
            'base_price' => 30000,
            'base_eta' => '45 - 60',
            'requires_prescription' => false,
            'icon_name' => 'accessibility_new',
            'warning_info' => 'Sesión a domicilio.',
            'placeholder_text' => 'Ej. dolor lumbar y rigidez',
        ]);

        ClinicalService::create([
            'id' => 'enfermeria',
            'title' => 'Enfermería a Domicilio',
            'short_title' => 'Enfermería',
            'subtitle' => 'Curaciones y procedimientos',
            'description' => 'Servicio de enfermería',
            'base_price' => 22000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'healing',
            'warning_info' => 'Procedimientos menores.',
            'placeholder_text' => 'Ej. curación de herida',
        ]);
    }

    private function makeProfessional(string $id, string $name, string $role = 'professional'): Professional
    {
        return Professional::forceCreate([
            'id' => $id,
            'name' => $name,
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('secret1234'),
            'role' => $role,
            'duty_status' => 'disponible',
            'coverage_zones' => 'Providencia',
        ]);
    }

    private function asAdmin(): array
    {
        return [
            'staff_authenticated' => true,
            'staff_role' => 'admin',
            'staff_id' => 'prof_admin',
            'staff_name' => 'Administrador Aura',
        ];
    }

    public function test_guest_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/admin/api/metrics')->assertStatus(401);
        $this->getJson('/admin/api/professionals')->assertStatus(401);
        $this->getJson('/admin/api/services')->assertStatus(401);
        $this->getJson('/admin/api/parametros')->assertStatus(401);
    }

    public function test_non_admin_professional_cannot_access_admin_endpoints(): void
    {
        $session = [
            'staff_authenticated' => true,
            'staff_role' => 'professional',
            'staff_id' => 'prof_medico',
            'staff_name' => 'Dr. Médico',
        ];

        $this->withSession($session)
            ->getJson('/admin/api/services')
            ->assertStatus(403);

        $this->withSession($session)
            ->getJson('/admin/api/parametros')
            ->assertStatus(403);
    }

    public function test_admin_can_list_services_catalogue(): void
    {
        $this->makeServices();

        $response = $this->withSession($this->asAdmin())
            ->getJson('/admin/api/services')
            ->assertStatus(200)
            ->json();

        $this->assertCount(3, $response);
        $ids = collect($response)->pluck('id')->all();
        $this->assertContains('medico', $ids);
        $this->assertContains('kine_motora', $ids);
        $this->assertContains('enfermeria', $ids);
    }

    public function test_admin_can_update_professional_assigned_services(): void
    {
        $this->makeServices();
        $prof = $this->makeProfessional('prof_patricia', 'Enf. Patricia Jara');

        $this->assertFalse($prof->attends('enfermeria'));
        $this->assertFalse($prof->attends('medico'));

        // Admin asigna enfermería y kinesiología
        $response = $this->withSession($this->asAdmin())
            ->postJson("/admin/api/professionals/{$prof->id}/services", [
                'services' => ['enfermeria', 'kine_motora'],
            ])
            ->assertStatus(200)
            ->json();

        $this->assertTrue($response['success']);
        $this->assertContains('enfermeria', $response['services']);
        $this->assertContains('kine_motora', $response['services']);
        $this->assertNotContains('medico', $response['services']);

        // Comprobar persistencia en la tabla pivote professional_service
        $profRefrescado = $prof->fresh();
        $this->assertTrue($profRefrescado->attends('enfermeria'));
        $this->assertTrue($profRefrescado->attends('kine_motora'));
        $this->assertFalse($profRefrescado->attends('medico'));

        // Admin incluye también médico
        $this->withSession($this->asAdmin())
            ->postJson("/admin/api/professionals/{$prof->id}/services", [
                'services' => ['enfermeria', 'medico'],
            ])
            ->assertStatus(200);

        $profRefrescado = $prof->fresh();
        $this->assertTrue($profRefrescado->attends('enfermeria'));
        $this->assertTrue($profRefrescado->attends('medico'));
        $this->assertFalse($profRefrescado->attends('kine_motora'));
    }

    public function test_admin_can_list_and_update_operation_parameters(): void
    {
        // 1. Listar parámetros y verificar que se inicializan con valores por defecto
        $list = $this->withSession($this->asAdmin())
            ->getJson('/admin/api/parametros')
            ->assertStatus(200)
            ->json();

        $claves = collect($list)->pluck('clave')->all();
        $this->assertContains('cola.casos_por_profesional', $claves);
        $this->assertContains('cola.escalado_minutos', $claves);
        $this->assertContains('cola.escalado_zonas_vecinas', $claves);
        $this->assertContains('cola.avisar_operaciones_minutos', $claves);

        // 2. Modificar parámetros desde el panel
        $this->withSession($this->asAdmin())
            ->postJson('/admin/api/parametros', [
                'parametros' => [
                    ['clave' => 'cola.casos_por_profesional', 'valor' => '4'],
                    ['clave' => 'cola.escalado_minutos', 'valor' => '8'],
                    ['clave' => 'cola.escalado_zonas_vecinas', 'valor' => '12'],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // 3. Comprobar que el modelo Parametro lee los nuevos valores (incluso con caché)
        $this->assertSame(4, Parametro::int('cola.casos_por_profesional', 2));
        $this->assertSame(8, Parametro::int('cola.escalado_minutos', 5));
        $this->assertSame(12, Parametro::int('cola.escalado_zonas_vecinas', 10));
    }
}
