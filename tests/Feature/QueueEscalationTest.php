<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Escalado de las solicitudes que nadie toma.
 *
 * El despacho voluntario no falla ruidosamente: si nadie toma, no pasa nada
 * visible. Estas pruebas cubren lo contrario —que sí pase algo— y sobre todo
 * que pase UNA vez: el comando corre cada minuto y lo primero que se rompe en
 * un escalado mal hecho es que repita el aviso hasta el hartazgo.
 */
class QueueEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Parametro::olvidarCache();
    }

    private function makeService(string $id = 'medico', string $titulo = 'Médico'): void
    {
        ClinicalService::create([
            'id' => $id,
            'title' => $titulo,
            'short_title' => $titulo,
            'subtitle' => 'Prestación de prueba',
            'description' => 'Prestación de prueba',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Sin urgencias vitales.',
            'placeholder_text' => 'Ej. fiebre y tos',
        ]);
    }

    private function makeProfessional(string $id, array $servicios, string $zonas = ''): Professional
    {
        $prof = Professional::forceCreate([
            'id' => $id,
            'name' => "Prof $id",
            'specialty' => 'Profesional clínico',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => 'disponible',
            'coverage_zones' => $zonas,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
        ]);
        $prof->services()->sync($servicios);

        return $prof;
    }

    private function encolar(
        string $id,
        int $haceMinutos,
        string $zona = 'Providencia',
        ?string $profesionalId = null,
        bool $agendada = false,
        string $estado = 'accepted',
    ): ServiceRequest {
        $paciente = User::create([
            'name' => "Paciente $id",
            'email' => "$id@paciente.cl",
            'password' => bcrypt('password123'),
        ]);

        $solicitud = ServiceRequest::create([
            'id' => $id,
            'user_id' => $paciente->id,
            'service_id' => 'medico',
            'status' => $estado,
            'patient_type' => 'self',
            'address_text' => "Calle 1, $zona",
            'zone' => $zona,
            'payment_method' => 'mercadopago',
            'payment_status' => 'approved',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $profesionalId,
            'is_scheduled' => $agendada,
        ]);

        $solicitud->forceFill(['created_at' => now()->subMinutes($haceMinutos)])->save();

        return $solicitud->fresh();
    }

    private function entrar(string $profId): void
    {
        $this->post('/doctor/login', [
            'email' => "$profId@aura.cl",
            'password' => 'clave-segura-123',
        ])->assertRedirect('/doctor');
    }

    public function test_a_request_inside_the_normal_wait_is_not_escalated(): void
    {
        $this->makeService();
        $this->encolar('req_reciente', 5);

        Artisan::call('cola:escalar');

        $this->assertSame(0, (int) ServiceRequest::find('req_reciente')->escalada_nivel);
        $this->assertNull(ServiceRequest::find('req_reciente')->escalada_at);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_it_escalates_once_and_does_not_repeat_the_alert(): void
    {
        $this->makeService();
        $this->encolar('req_vieja', 20);

        Artisan::call('cola:escalar');

        $primera = ServiceRequest::find('req_vieja');
        $this->assertSame(1, (int) $primera->escalada_nivel);
        $this->assertNotNull($primera->escalada_at);

        // El comando corre cada minuto. Volver a pasar no puede volver a
        // avisar: seria el mismo push sesenta veces por hora a todos los
        // profesionales del servicio.
        $marca = $primera->escalada_at;
        $this->travel(1)->minutes();
        Artisan::call('cola:escalar');

        $segunda = ServiceRequest::find('req_vieja');
        $this->assertSame(1, (int) $segunda->escalada_nivel);
        $this->assertEquals($marca, $segunda->escalada_at);
    }

    public function test_the_second_level_warns_operations_and_talks_to_the_patient(): void
    {
        $this->makeService();
        $this->encolar('req_olvidada', 35);

        Artisan::call('cola:escalar');

        $solicitud = ServiceRequest::find('req_olvidada');
        $this->assertSame(2, (int) $solicitud->escalada_nivel);

        // Al paciente se le habla solo aqui: a los quince minutos no ha
        // cambiado nada para el y avisarle seria alarma sin nada que hacer.
        $mensajes = ChatMessage::where('service_request_id', 'req_olvidada')->get();
        $this->assertCount(1, $mensajes);
        $this->assertSame('system', $mensajes->first()->sender);
        $this->assertStringContainsString('Seguimos buscando', $mensajes->first()->text);
        $this->assertStringContainsString('cancelarla', $mensajes->first()->text);

        // Y una sola vez, tambien.
        $this->travel(10)->minutes();
        Artisan::call('cola:escalar');
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_the_thresholds_come_from_the_parameters_table(): void
    {
        $this->makeService();
        $this->encolar('req_cinco', 6);

        Artisan::call('cola:escalar');
        $this->assertSame(0, (int) ServiceRequest::find('req_cinco')->escalada_nivel);

        // Operaciones acorta el corte sin desplegar nada.
        Parametro::fijar('cola.escalado_minutos', 5);
        Artisan::call('cola:escalar');

        $this->assertSame(1, (int) ServiceRequest::find('req_cinco')->escalada_nivel);
    }

    public function test_it_ignores_requests_that_are_not_waiting_in_the_queue(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_a', ['medico']);

        $this->encolar('req_tomada', 40, 'Providencia', 'prof_a');
        $this->encolar('req_agendada', 40, 'Providencia', null, true);
        $this->encolar('req_cerrada', 40, 'Providencia', null, false, 'completed');
        $this->encolar('req_impaga', 40, 'Providencia', null, false, 'pending_payment');

        Artisan::call('cola:escalar');

        foreach (['req_tomada', 'req_agendada', 'req_cerrada', 'req_impaga'] as $id) {
            $this->assertSame(
                0,
                (int) ServiceRequest::find($id)->escalada_nivel,
                "$id no deberia escalar",
            );
        }
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_another_zone_is_only_offered_once_it_has_escalated(): void
    {
        $this->makeService();
        // Providencia tiene quien la cubra; el que mira es de Ñuñoa.
        $this->makeProfessional('prof_a', ['medico'], 'Providencia');
        $this->makeProfessional('prof_b', ['medico'], 'Ñuñoa');
        $this->encolar('req_prov', 20, 'Providencia');

        $this->entrar('prof_b');

        // Antes de escalar no le aparece: si no, "que mande la zona" no
        // significaria nada y el primero que refrescara se llevaria los
        // pacientes de toda la ciudad.
        $antes = $this->getJson('/doctor/api/queue')->assertStatus(200)->json();
        $this->assertSame([], $antes['servicios']);

        Artisan::call('cola:escalar');

        $despues = $this->getJson('/doctor/api/queue')->assertStatus(200)->json('servicios.0');
        $this->assertCount(0, $despues['en_mi_zona']);
        $this->assertCount(1, $despues['fuera_de_zona']);
        $this->assertSame('req_prov', $despues['fuera_de_zona'][0]['id']);
        $this->assertSame(1, $despues['fuera_de_zona'][0]['escalada_nivel']);
    }

    public function test_a_zone_nobody_covers_is_offered_immediately(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_b', ['medico'], 'Ñuñoa');
        // Nadie declara Puente Alto. Esperar quince minutos a que escale para
        // que alguien la vea seria dejar huerfana la comuna sin cobertura,
        // que es exactamente el caso que el escalado deberia resolver antes.
        $this->encolar('req_lejos', 1, 'Puente Alto');

        $this->entrar('prof_b');

        $servicio = $this->getJson('/doctor/api/queue')->assertStatus(200)->json('servicios.0');
        $this->assertCount(1, $servicio['fuera_de_zona']);
        $this->assertSame('req_lejos', $servicio['fuera_de_zona'][0]['id']);
        $this->assertSame(0, $servicio['fuera_de_zona'][0]['escalada_nivel']);
    }

    public function test_the_operations_panel_counts_what_nobody_is_taking(): void
    {
        $this->makeService();
        $admin = Professional::forceCreate([
            'id' => 'staff_admin',
            'name' => 'Coordinación',
            'specialty' => 'Administración',
            'consultation_price' => 0,
            'consultation_duration_minutes' => 30,
            'active' => false,
            'email' => 'admin@aura.cl',
            'password' => Hash::make('clave-segura-123'),
            'role' => 'admin',
        ]);
        $this->assertNotNull($admin);

        $this->encolar('req_1', 2);
        $this->encolar('req_2', 20);
        $this->encolar('req_3', 40);

        Artisan::call('cola:escalar');

        $this->post('/doctor/login', ['email' => 'admin@aura.cl', 'password' => 'clave-segura-123']);

        $metricas = $this->getJson('/admin/api/metrics')->assertStatus(200)->json();
        $this->assertSame(3, $metricas['queued_requests']);
        $this->assertSame(2, $metricas['escalated_requests']);
        $this->assertSame(1, $metricas['needs_operations']);
        $this->assertGreaterThanOrEqual(40, $metricas['longest_wait_minutes']);
    }

    public function test_a_backlogged_request_still_gets_offered_before_the_operations_alert(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_a', ['medico'], 'Providencia');
        // Cuarenta minutos: pasa los dos cortes de una vez. Es lo que ocurre en
        // el primer arranque tras desplegar, o tras un rato con el scheduler
        // caido. Saltarse el nivel 1 dejaria a los profesionales sin enterarse
        // justo de la solicitud que mas falta hace que alguien tome.
        $this->encolar('req_atrasada', 40);

        Artisan::call('cola:escalar');
        $salida = Artisan::output();

        $this->assertSame(2, (int) ServiceRequest::find('req_atrasada')->escalada_nivel);
        $this->assertStringContainsString('ampliada fuera de zona', $salida);
        $this->assertStringContainsString('avisado a operaciones', $salida);
        $this->assertStringContainsString('1 a nivel 1, 1 a nivel 2', $salida);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->makeService();
        $this->encolar('req_vieja', 40);

        Artisan::call('cola:escalar', ['--dry-run' => true]);

        $this->assertSame(0, (int) ServiceRequest::find('req_vieja')->escalada_nivel);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertStringContainsString('simulacion', Artisan::output());
    }
}
