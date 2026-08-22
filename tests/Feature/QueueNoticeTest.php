<?php

namespace Tests\Feature;

use App\Jobs\NotifyQueuedRequest;
use App\Models\ClinicalService;
use App\Models\Parametro;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\QueueNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aviso a los profesionales cuando entra un paciente a la cola.
 *
 * Hasta aquí una solicitud pagada entraba en silencio: nadie se enteraba hasta
 * que algún profesional refrescara el portal por su cuenta, así que lo rápido
 * que se atendiera dependía de quién tuviera la pestaña abierta.
 *
 * Lo que estas pruebas cuidan sobre todo es a quién NO se le manda. Un aviso
 * que llega a gente que no puede tomarlo es la manera más rápida de que se
 * silencien las notificaciones de la aplicación, y entonces deja de servir
 * justo cuando hace falta.
 */
class QueueNoticeTest extends TestCase
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

    private function makeProfessional(
        string $id,
        array $servicios,
        string $zonas = '',
        string $duty = 'disponible',
    ): Professional {
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

    private function encolar(string $id, string $zona = 'Providencia', ?string $profesionalId = null): ServiceRequest
    {
        $paciente = User::create([
            'name' => "Paciente $id",
            'email' => "$id@paciente.cl",
            'password' => bcrypt('password123'),
        ]);

        return ServiceRequest::create([
            'id' => $id,
            'user_id' => $paciente->id,
            'service_id' => 'medico',
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
            'professional_id' => $profesionalId,
        ]);
    }

    private function avisos(): QueueNotifier
    {
        return app(QueueNotifier::class);
    }

    public function test_a_paid_request_queues_a_notice_for_the_professionals(): void
    {
        Queue::fake();
        $this->makeService();

        $paciente = User::create([
            'name' => 'Paciente',
            'email' => 'nuevo@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $token = $paciente->createToken('test')->plainTextToken;

        $respuesta = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/bookings', [
                'service_id' => 'medico',
                'patient_type' => 'self',
                'address_text' => 'Av. Siempre Viva 1, Providencia',
                'symptoms_description' => 'fiebre alta y tos seca',
                'final_price' => 25000,
                'eta_minutes' => 30,
            ])->assertStatus(201);

        $id = $respuesta->json('id');

        // Encolado, no enviado en caliente: esto corre dentro del callback de
        // pago y no puede quedarse esperando a FCM.
        Queue::assertPushed(
            NotifyQueuedRequest::class,
            fn (NotifyQueuedRequest $job) => $job->serviceRequestId === $id && $job->motivo === 'nueva',
        );
    }

    public function test_only_free_professionals_of_the_service_in_the_zone_are_told(): void
    {
        $this->makeService();
        $this->makeService('kine', 'Kinesiología');

        $this->makeProfessional('prof_ok', ['medico'], 'Providencia');
        // Al tope de atenciones: el servidor le responderia 422 si la intenta
        // tomar, asi que avisarle es ruido que no puede atender.
        $this->makeProfessional('prof_tope', ['medico'], 'Providencia', 'ocupado');
        $this->makeProfessional('prof_libre_pero_lejos', ['medico'], 'Puente Alto');
        $this->makeProfessional('prof_otra_area', ['kine'], 'Providencia');
        $this->makeProfessional('prof_fuera_turno', ['medico'], 'Providencia', 'desconectado');

        $solicitud = $this->encolar('req_1');

        $this->assertSame(1, $this->avisos()->announceQueued($solicitud, 'nueva'));
        $this->assertNotNull(ServiceRequest::find('req_1')->cola_avisada_at);
    }

    public function test_an_escalated_request_reaches_beyond_its_zone(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_zona', ['medico'], 'Providencia');
        $this->makeProfessional('prof_lejos', ['medico'], 'Puente Alto');

        $solicitud = $this->encolar('req_esc');
        $solicitud->forceFill(['escalada_nivel' => 1])->save();

        $this->assertSame(2, $this->avisos()->announceQueued($solicitud->fresh(), 'escalada'));
    }

    public function test_widening_beyond_the_zone_can_be_switched_off(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_zona', ['medico'], 'Providencia');
        $this->makeProfessional('prof_lejos', ['medico'], 'Puente Alto');

        // El parámetro tiene un solo significado y manda en los dos sitios: si
        // el escalado no amplía, ni se ofrece fuera del sector ni se avisa
        // fuera del sector.
        Parametro::fijar('cola.escalado_zonas_vecinas', false);

        $solicitud = $this->encolar('req_esc');
        $solicitud->forceFill(['escalada_nivel' => 1])->save();

        $this->assertSame(1, $this->avisos()->announceQueued($solicitud->fresh(), 'escalada'));

        $this->post('/doctor/login', ['email' => 'prof_lejos@aura.cl', 'password' => 'clave-segura-123']);
        $this->assertSame([], $this->getJson('/doctor/api/queue')->assertStatus(200)->json('servicios'));
    }

    public function test_a_retried_payment_webhook_does_not_notify_twice(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_ok', ['medico'], 'Providencia');
        $solicitud = $this->encolar('req_1');

        $this->assertSame(1, $this->avisos()->announceQueued($solicitud, 'nueva'));

        // Mercado Pago puede llamar dos o tres veces a la misma confirmación.
        $this->assertSame(0, $this->avisos()->announceQueued($solicitud->fresh(), 'nueva'));

        // Pasada la ventana sí se vuelve a insistir.
        $this->travel(6)->minutes();
        $this->assertSame(1, $this->avisos()->announceQueued($solicitud->fresh(), 'nueva'));
    }

    public function test_nothing_is_sent_about_a_request_somebody_already_took(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_ok', ['medico'], 'Providencia');
        $this->makeProfessional('prof_dueno', ['medico'], 'Providencia');

        // Entre que el trabajo se encola y corre, alguien pudo tomarla.
        $solicitud = $this->encolar('req_tomada', 'Providencia', 'prof_dueno');

        $this->assertSame(0, $this->avisos()->announceQueued($solicitud, 'nueva'));
        $this->assertNull(ServiceRequest::find('req_tomada')->cola_avisada_at);
    }

    public function test_a_zone_with_nobody_free_is_marked_so_it_is_not_retried_at_once(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_tope', ['medico'], 'Providencia', 'ocupado');

        $solicitud = $this->encolar('req_sin_nadie');

        $this->assertSame(0, $this->avisos()->announceQueued($solicitud, 'nueva'));
        // Se marca igual: si no, cada reintento del webhook volveria a recorrer
        // la plantilla entera para no mandar nada.
        $this->assertNotNull(ServiceRequest::find('req_sin_nadie')->cola_avisada_at);
    }

    public function test_returning_a_request_to_the_queue_tells_the_others(): void
    {
        Queue::fake();
        $this->makeService();
        $this->makeProfessional('prof_a', ['medico'], 'Providencia');
        $this->encolar('req_suelta');

        $this->post('/doctor/login', ['email' => 'prof_a@aura.cl', 'password' => 'clave-segura-123']);
        $this->postJson('/doctor/api/bookings/req_suelta/claim')->assertStatus(200);
        $this->postJson('/doctor/api/bookings/req_suelta/release')->assertStatus(200);

        Queue::assertPushed(
            NotifyQueuedRequest::class,
            fn (NotifyQueuedRequest $job) => $job->serviceRequestId === 'req_suelta'
                && $job->motivo === 'devuelta',
        );
    }

    public function test_the_job_reads_the_request_when_it_runs_and_not_when_it_is_queued(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_ok', ['medico'], 'Providencia');
        $solicitud = $this->encolar('req_1');

        // El trabajo lleva el id, no el modelo: si alguien la toma mientras
        // espera en la cola de Laravel, al ejecutarse no manda nada.
        $job = new NotifyQueuedRequest('req_1', 'nueva');
        $solicitud->forceFill(['professional_id' => 'prof_ok'])->save();

        $job->handle($this->avisos());

        $this->assertNull(ServiceRequest::find('req_1')->cola_avisada_at);
    }
}
