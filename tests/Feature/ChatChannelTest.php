<?php

namespace Tests\Feature;

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

    private function makeProfessional(string $id = 'prof_chat'): Professional
    {
        return Professional::create([
            'id' => $id,
            'name' => 'Dra. Canal',
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
        ]);
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

    public function test_status_updates_are_signed_and_land_in_the_thread(): void
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

        $this->withHeaders($this->asPatient($patient))
            ->getJson('/api/bookings/req_pasos/chat')
            ->assertStatus(200)
            ->assertJsonCount(1)
            // Iba sin firma: el paciente veía un aviso de que "alguien" venía
            // en camino sin saber quién.
            ->assertJsonPath('0.sender_name', 'Dra. Canal')
            ->assertJsonPath('0.sender', 'provider');
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
