<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cola de pacientes por servicio y toma explícita.
 */
class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Parametro::olvidarCache();
    }

    private function makeService(string $id, string $titulo): void
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

    private function makeProfessional(string $id, array $servicios, string $zonas = '', string $duty = 'disponible'): Professional
    {
        $prof = Professional::forceCreate([
            'id' => $id,
            'name' => "Prof $id",
            'specialty' => 'Profesional clínico',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => $duty,
            'coverage_zones' => $zonas,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
        ]);
        $prof->services()->sync($servicios);

        return $prof;
    }

    private function makePatient(string $email): User
    {
        return User::create([
            'name' => "Paciente $email",
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);
    }

    private function encolar(string $id, string $serviceId, string $zona, ?int $haceMinutos = 0): ServiceRequest
    {
        $paciente = $this->makePatient("$id@paciente.cl");

        $solicitud = ServiceRequest::create([
            'id' => $id,
            'user_id' => $paciente->id,
            'service_id' => $serviceId,
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => "Calle 1, $zona",
            'zone' => $zona,
            'payment_method' => 'mercadopago',
            'payment_status' => 'approved',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => null,
        ]);

        if ($haceMinutos) {
            $solicitud->forceFill(['created_at' => now()->subMinutes($haceMinutos)])->save();
        }

        return $solicitud->fresh();
    }

    private function entrar(string $profId): void
    {
        $this->post('/doctor/login', [
            'email' => "$profId@aura.cl",
            'password' => 'clave-segura-123',
        ])->assertRedirect('/doctor');
    }

    public function test_the_queue_only_shows_services_the_professional_is_enabled_for(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeService('kine_motora', 'Kinesiología');

        $this->makeProfessional('prof_kine', ['kine_motora']);
        $this->encolar('req_med', 'medico', 'Providencia');
        $this->encolar('req_kine', 'kine_motora', 'Providencia');

        $this->entrar('prof_kine');

        $respuesta = $this->getJson('/doctor/api/queue')->assertStatus(200)->json();

        $this->assertCount(1, $respuesta['servicios']);
        $this->assertSame('kine_motora', $respuesta['servicios'][0]['service_id']);
        $this->assertSame('req_kine', $respuesta['servicios'][0]['en_mi_zona'][0]['id']);
    }

    public function test_a_professional_without_services_gets_an_explanation_not_an_empty_queue(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_sin', []);
        $this->encolar('req_med', 'medico', 'Providencia');

        $this->entrar('prof_sin');

        $respuesta = $this->getJson('/doctor/api/queue')->assertStatus(200)->json();

        $this->assertSame([], $respuesta['servicios']);
        // Sin esto concluiría que no hay pacientes, cuando lo que pasa es que
        // nadie le habilitó ningún servicio.
        $this->assertArrayHasKey('aviso', $respuesta);
    }

    public function test_own_zone_comes_first_and_each_group_is_ordered_by_age(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_prov', ['medico'], 'Providencia');

        // Fuera de zona y muy antigua: no debe adelantar a las de la zona.
        // Aparece porque a Rancagua no la cubre nadie —una comuna sin cobertura
        // no se deja huerfana esperando a que escale—, no porque las de otra
        // zona se ofrezcan siempre. Ver QueueEscalationTest.
        $this->encolar('req_lejos', 'medico', 'Rancagua', haceMinutos: 90);
        $this->encolar('req_nueva', 'medico', 'Providencia', haceMinutos: 5);
        $this->encolar('req_vieja', 'medico', 'Providencia', haceMinutos: 40);

        $this->entrar('prof_prov');

        $servicio = $this->getJson('/doctor/api/queue')->assertStatus(200)->json('servicios.0');

        $this->assertSame(
            ['req_vieja', 'req_nueva'],
            array_column($servicio['en_mi_zona'], 'id'),
            'Dentro de la zona propia manda la antigüedad.',
        );
        $this->assertSame(['req_lejos'], array_column($servicio['fuera_de_zona'], 'id'));
        $this->assertGreaterThanOrEqual(39, $servicio['en_mi_zona'][0]['esperando_minutos']);
    }

    public function test_claiming_assigns_the_professional_and_says_so_in_the_thread(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');
        $this->postJson('/doctor/api/bookings/req_1/claim')
            ->assertStatus(200)
            ->assertJsonPath('casos_abiertos', 1);

        $this->assertSame('prof_a', ServiceRequest::find('req_1')->professional_id);

        $aviso = ChatMessage::where('service_request_id', 'req_1')->first();
        $this->assertNotNull($aviso);
        $this->assertSame('system', $aviso->sender);
        $this->assertStringContainsString('Prof prof_a', $aviso->text);
    }

    public function test_a_second_professional_gets_a_conflict_not_a_silent_overwrite(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->makeProfessional('prof_b', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);
        $this->post('/doctor/logout');

        $this->entrar('prof_b');
        $this->postJson('/doctor/api/bookings/req_1/claim')
            ->assertStatus(409)
            ->assertJsonPath('error', 'Otro profesional la tomó hace un momento.');

        $this->assertSame('prof_a', ServiceRequest::find('req_1')->professional_id);
    }

    public function test_claiming_is_refused_without_the_service_or_off_duty(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeService('kine_motora', 'Kinesiología');

        $this->makeProfessional('prof_kine', ['kine_motora']);
        $this->makeProfessional('prof_off', ['medico'], '', 'desconectado');
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_kine');
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(403);
        $this->post('/doctor/logout');

        $this->entrar('prof_off');
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(403);

        $this->assertNull(ServiceRequest::find('req_1')->professional_id);
    }

    public function test_the_cap_of_simultaneous_cases_is_the_parameter(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');
        $this->encolar('req_2', 'medico', 'Providencia');

        $this->entrar('prof_a');

        // Con el tope por defecto en 1, la segunda se rechaza.
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);
        $this->postJson('/doctor/api/bookings/req_2/claim')->assertStatus(422);

        // Subiendo el parámetro, la misma acción pasa.
        Parametro::fijar('cola.casos_por_profesional', 2);
        $this->postJson('/doctor/api/bookings/req_2/claim')->assertStatus(200);

        $this->assertSame('prof_a', ServiceRequest::find('req_2')->professional_id);
    }

    public function test_releasing_returns_the_request_to_the_queue_and_tells_the_patient(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);
        // Un segundo entre ambas: `created_at` guarda segundos y sin esto la
        // toma y la devolucion caen en el mismo instante, con lo que "el ultimo
        // mensaje" deja de estar definido.
        $this->travel(1)->seconds();
        $this->postJson('/doctor/api/bookings/req_1/release')
            ->assertStatus(200)
            ->assertJsonPath('casos_abiertos', 0);

        $solicitud = ServiceRequest::find('req_1');
        $this->assertNull($solicitud->professional_id);
        $this->assertSame('accepted', $solicitud->status);
        // La posición del que la soltó deja de tener sentido.
        $this->assertNull($solicitud->professional_lat);

        $aviso = ChatMessage::where('service_request_id', 'req_1')
            ->where('sender', 'system')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('vuelve a la cola', mb_strtolower($aviso->text));
    }

    public function test_an_attention_already_under_way_cannot_be_released(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);
        $this->postJson('/doctor/api/bookings/req_1/status', ['status' => 'en_atencion'])->assertStatus(200);

        $this->postJson('/doctor/api/bookings/req_1/release')->assertStatus(422);
        $this->assertSame('prof_a', ServiceRequest::find('req_1')->professional_id);
    }

    public function test_broadcasting_location_no_longer_claims_a_queued_request(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');

        // El portal emite GPS en cuanto se selecciona una tarjeta. Antes eso
        // bastaba para quedarse la solicitud sin pulsar nada.
        $this->postJson('/doctor/api/bookings/req_1/location', [
            'lat' => -33.42,
            'lng' => -70.61,
        ])->assertStatus(409);

        $this->assertNull(ServiceRequest::find('req_1')->professional_id);
    }

    public function test_advancing_or_writing_before_taking_is_refused(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');

        $this->entrar('prof_a');

        // Avanzar el estado y escribirle al paciente tomaban la solicitud de
        // paso. Eso hacia que "mirar" una tarjeta de la cola equivaliera a
        // quedarsela, y que el paciente recibiera un "voy en camino" de alguien
        // que no habia decidido ir.
        $this->postJson('/doctor/api/bookings/req_1/status', ['status' => 'en_camino'])
            ->assertStatus(409);

        $this->postJson('/doctor/api/bookings/req_1/messages', ['text' => 'Hola'])
            ->assertStatus(409);

        $this->assertNull(ServiceRequest::find('req_1')->professional_id);
        $this->assertSame('accepted', ServiceRequest::find('req_1')->status);
        $this->assertDatabaseCount('chat_messages', 0);

        // Tomandola primero, ambas cosas funcionan.
        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);
        $this->postJson('/doctor/api/bookings/req_1/status', ['status' => 'en_camino'])
            ->assertStatus(200);
        $this->postJson('/doctor/api/bookings/req_1/messages', ['text' => 'Hola'])
            ->assertStatus(201);
    }

    public function test_the_queue_reports_who_is_asking_and_how_loaded_they_are(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico']);
        $this->encolar('req_1', 'medico', 'Providencia');
        $this->encolar('req_2', 'medico', 'Providencia');

        $this->entrar('prof_a');

        // La interfaz necesita estos tres datos para separar "mis atenciones"
        // de la cola y para saber si el boton de tomar va habilitado.
        $antes = $this->getJson('/doctor/api/queue')->assertStatus(200)->json();
        $this->assertSame('prof_a', $antes['professional_id']);
        $this->assertSame(0, $antes['casos_abiertos']);
        $this->assertSame(1, $antes['tope']);

        $this->postJson('/doctor/api/bookings/req_1/claim')->assertStatus(200);

        $despues = $this->getJson('/doctor/api/queue')->assertStatus(200)->json();
        $this->assertSame(1, $despues['casos_abiertos']);
        // La tomada sale de la cola; queda solo la otra.
        $this->assertSame(1, $despues['servicios'][0]['esperando']);
    }

    public function test_the_mobile_app_takes_and_releases_through_the_token_api(): void
    {
        $this->makeService('medico', 'Médico');
        $this->makeProfessional('prof_a', ['medico'], 'Providencia');
        $this->encolar('req_1', 'medico', 'Providencia');

        $cuenta = User::create([
            'name' => 'Staff movil',
            'email' => 'movil@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $cuenta->forceFill(['role' => 'doctor_provider', 'professional_id' => 'prof_a'])->save();
        $headers = ['Authorization' => 'Bearer ' . $cuenta->createToken('test')->plainTextToken];

        // La app y el portal comparten controlador: lo que se arregla en uno
        // tiene que valer en el otro sin escribirlo dos veces.
        $this->withHeaders($headers)->getJson('/api/staff/queue')
            ->assertStatus(200)
            ->assertJsonPath('professional_id', 'prof_a')
            ->assertJsonPath('tope', 1);

        $this->withHeaders($headers)
            ->postJson('/api/staff/bookings/req_1/status', ['status' => 'en_camino'])
            ->assertStatus(409);

        $this->withHeaders($headers)->postJson('/api/staff/bookings/req_1/claim')
            ->assertStatus(200);
        $this->assertSame('prof_a', ServiceRequest::find('req_1')->professional_id);

        $this->withHeaders($headers)->postJson('/api/staff/bookings/req_1/release')
            ->assertStatus(200);
        $this->assertNull(ServiceRequest::find('req_1')->professional_id);
    }
}
