<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El canal de comunicación directa, de punta a punta.
 *
 * Lo que se rompió una vez fue justamente esto: el profesional escribía desde
 * el portal, el mensaje quedaba guardado, y la app del paciente no volvía a
 * pedir el hilo nunca. El servidor tiene que devolverlo por la vía que la app
 * consulta —GET /api/bookings/{id}/chat— y solo al paciente que corresponde.
 */
class ChatChannelTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): void
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
            'warning_info' => 'Servicio no apto para urgencias de riesgo vital.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    /**
     * Crea el profesional y lo habilita en el catalogo existente. Sin filas en
     * `professional_service` el claim responde 403.
     */
    private function makeProfessional(string $id = 'prof_chat'): Professional
    {
        $prof = Professional::forceCreate([
            'id' => $id,
            'name' => $id === 'prof_chat' ? 'Dra. Canal' : 'Dr. Otro',
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
            'duty_status' => 'disponible',
            'coverage_zones' => 'Providencia',
        ]);

        $prof->services()->sync(ClinicalService::pluck('id')->all());

        return $prof;
    }

    private function makeBooking(string $id, User $patient, ?string $professionalId = null): ServiceRequest
    {
        return ServiceRequest::create([
            'id' => $id,
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => 'Calle 1, Providencia',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $professionalId,
        ]);
    }

    private function makePatient(string $email = 'paciente@aura.cl'): User
    {
        return User::create([
            'name' => 'Paciente Canal',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);
    }

    /** Cabeceras del paciente tal como las manda la app: token Sanctum. */
    private function asPatient(User $patient): array
    {
        return ['Authorization' => 'Bearer ' . $patient->createToken('test')->plainTextToken];
    }

    public function test_a_message_written_in_the_portal_reaches_the_patient_app(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $patient = $this->makePatient();
        $this->makeBooking('req_canal', $patient, 'prof_chat');

        $this->post('/doctor/login', [
            'email' => 'prof_chat@aura.cl',
            'password' => 'clave-segura-123',
        ])->assertRedirect('/doctor');

        $this->postJson('/doctor/api/bookings/req_canal/messages', [
            'text' => '¿Sigue con fiebre?',
        ])->assertStatus(201);

        $this->post('/doctor/logout');

        // La app del paciente lee por aquí: es el único endpoint que consulta.
        $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/req_canal/chat')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.sender', 'provider')
            ->assertJsonPath('0.sender_name', 'Dra. Canal')
            ->assertJsonPath('0.text', '¿Sigue con fiebre?');
    }

    public function test_status_changes_are_recorded_in_the_thread_without_faking_a_voice(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $patient = $this->makePatient();
        $this->makeBooking('req_pasos', $patient, 'prof_chat');

        $this->post('/doctor/login', [
            'email' => 'prof_chat@aura.cl',
            'password' => 'clave-segura-123',
        ]);

        $this->postJson('/doctor/api/bookings/req_pasos/status', ['status' => 'en_camino'])
            ->assertStatus(200);
        $this->post('/doctor/logout');

        // Queda anotado, y queda anotado como un hecho del sistema.
        //
        // Aqui hubo un mensaje en primera persona —"He iniciado el trayecto
        // hacia tu ubicacion"— firmado como el profesional, que nadie habia
        // tecleado. Quitarlo fue correcto. Quitarlo sin dejar nada dejo al
        // paciente leyendo que su solicitud seguia en la cola mientras el
        // profesional estaba de camino a su casa.
        $hilo = $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/req_pasos/chat')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->json();

        $this->assertSame('system', $hilo[0]['sender']);
        $this->assertNull($hilo[0]['sender_name'] ?? null);
        $this->assertStringContainsString('Dra. Canal', $hilo[0]['text']);
        $this->assertStringContainsString('salió', $hilo[0]['text']);
        // Sin voz prestada: el sistema narra en tercera persona.
        $this->assertStringNotContainsString('He iniciado', $hilo[0]['text']);
        $this->assertStringNotContainsString('Hola, soy', $hilo[0]['text']);
    }

    public function test_the_thread_is_not_readable_by_another_patient(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $owner = $this->makePatient('duenio@aura.cl');
        $intruder = $this->makePatient('intruso@aura.cl');
        $this->makeBooking('req_ajeno', $owner, 'prof_chat');

        $headers = $this->asPatient($intruder);

        $this->withHeaders($headers)->getJson('/api/bookings/req_ajeno/chat')->assertStatus(404);
        $this->withHeaders($headers)->postJson('/api/bookings/req_ajeno/chat', ['text' => 'hola'])->assertStatus(404);
        // El stream de seguimiento lleva el hilo completo dentro; se abría sin
        // comprobar de quién era la reserva.
        $this->withHeaders($headers)->getJson('/api/bookings/req_ajeno/sse')->assertStatus(404);
    }

    public function test_taking_an_unassigned_request_assigns_the_real_professional(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $patient = $this->makePatient();
        // Sin asignar: como queda una solicitud despues de pagarse, esperando
        // que alguien de la guardia la tome.
        $this->makeBooking('req_toma', $patient, null);

        // El mensaje firmado como profesional que decia "me dirijo hacia tu
        // ubicacion" antes de que nadie la tomara ya no se escribe.
        $this->assertDatabaseCount('chat_messages', 0);

        $this->post('/doctor/login', [
            'email' => 'prof_chat@aura.cl',
            'password' => 'clave-segura-123',
        ])->assertRedirect('/doctor');

        $this->postJson('/doctor/api/bookings/req_toma/claim')->assertStatus(200);
        $this->post('/doctor/logout');

        $this->assertSame('prof_chat', ServiceRequest::find('req_toma')->professional_id);

        // Que alguien tomo tu caso es lo primero que el paciente necesita saber,
        // y el hilo es donde lo va a buscar cuando abra la aplicacion despues.
        $aviso = ChatMessage::where('service_request_id', 'req_toma')->first();

        $this->assertNotNull($aviso);
        $this->assertSame('system', $aviso->sender);
        $this->assertNull($aviso->sender_name);
        $this->assertStringContainsString('Dra. Canal', $aviso->text);
        $this->assertStringContainsString('tomó tu atención', $aviso->text);
    }

    public function test_a_request_already_taken_cannot_be_claimed_by_someone_else(): void
    {
        $this->makeService();
        $this->makeProfessional('prof_chat');
        $this->makeProfessional('prof_otro');
        $patient = $this->makePatient();
        $this->makeBooking('req_disputa', $patient, null);

        $this->post('/doctor/login', ['email' => 'prof_chat@aura.cl', 'password' => 'clave-segura-123']);
        $this->postJson('/doctor/api/bookings/req_disputa/claim')->assertStatus(200);
        $this->post('/doctor/logout');

        $this->assertSame('prof_chat', ServiceRequest::find('req_disputa')->professional_id);

        // El segundo ya no la ve ni puede operarla. La toma se resuelve con un
        // UPDATE condicionado a que siga libre, no leyendo y escribiendo
        // despues, que es lo que dejaba que el segundo pisara al primero.
        $this->post('/doctor/login', ['email' => 'prof_otro@aura.cl', 'password' => 'clave-segura-123']);
        $this->postJson('/doctor/api/bookings/req_disputa/claim')->assertStatus(409);
        $this->post('/doctor/logout');

        $this->assertSame('prof_chat', ServiceRequest::find('req_disputa')->professional_id);
    }

    public function test_the_unread_summary_counts_each_thread_without_downloading_them(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $patient = $this->makePatient();
        $otro = User::create([
            'name' => 'Otro',
            'email' => 'otro@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        $this->makeBooking('req_uno', $patient, 'prof_chat');
        $this->makeBooking('req_dos', $patient, 'prof_chat');
        $this->makeBooking('req_ajena', $otro, 'prof_chat');
        $this->makeBooking('req_cerrada', $patient, 'prof_chat')->update(['status' => 'completed']);

        $escribir = function (string $booking, string $sender, string $texto) {
            ChatMessage::create([
                'id' => ChatMessage::nextId('m_' . uniqid()),
                'service_request_id' => $booking,
                'sender' => $sender,
                'text' => $texto,
                'timestamp' => '10:00',
            ]);
        };

        $escribir('req_uno', 'system', 'Un profesional tomó tu atención.');
        $escribir('req_uno', 'provider', '¿Sigue con fiebre?');
        // El propio paciente no cuenta: sus mensajes no le van a quedar sin leer.
        $escribir('req_uno', 'patient', 'Sí, desde ayer');
        $escribir('req_dos', 'system', 'Un profesional tomó tu atención.');
        $escribir('req_ajena', 'provider', 'De otro paciente');
        $escribir('req_cerrada', 'provider', 'De una atención ya cerrada');

        $resumen = $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/unread-summary')
            ->assertStatus(200)
            ->json();

        $porSolicitud = collect($resumen)->pluck('from_provider', 'booking_id');

        $this->assertSame(2, $porSolicitud['req_uno']);
        $this->assertSame(1, $porSolicitud['req_dos']);
        // Ni la de otro paciente ni la ya cerrada aparecen.
        $this->assertArrayNotHasKey('req_ajena', $porSolicitud->all());
        $this->assertArrayNotHasKey('req_cerrada', $porSolicitud->all());
    }

    public function test_patient_message_is_stored_and_gets_no_automatic_reply(): void
    {
        $this->makeService();
        $this->makeProfessional();
        $patient = $this->makePatient();
        $this->makeBooking('req_ida', $patient, 'prof_chat');

        $headers = $this->asPatient($patient);

        $this->withHeaders($headers)
            ->postJson('/api/bookings/req_ida/chat', ['text' => 'Tengo una duda'])
            ->assertStatus(201)
            ->assertJsonPath('patient_message.sender', 'patient');

        // Un solo mensaje: el bot de respuestas automáticas ya no compite con
        // el profesional real.
        $this->withHeaders($headers)
            ->getJson('/api/bookings/req_ida/chat')
            ->assertStatus(200)
            ->assertJsonCount(1);

        // El mismo tope que aplica el portal al responder.
        $this->withHeaders($headers)
            ->postJson('/api/bookings/req_ida/chat', ['text' => str_repeat('x', 1001)])
            ->assertStatus(422);
    }
}
