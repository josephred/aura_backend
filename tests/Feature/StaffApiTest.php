<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff area consumed by the mobile app. It shares controllers with the
 * web portal, so these tests focus on the token transport and the role gate.
 */
class StaffApiTest extends TestCase
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
            // NOT NULL en el catálogo; sin ellas el insert falla.
            'warning_info' => 'Servicio no apto para urgencias de riesgo vital.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    /**
     * Crea el profesional y lo habilita en el catalogo existente. Sin filas en
     * `professional_service` no puede tomar nada.
     */
    private function makeProfessional(string $id = 'prof_api'): Professional
    {
        $prof = Professional::create([
            'id' => $id,
            'name' => 'Dra. API',
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => 'disponible',
            'coverage_zones' => 'Providencia',
        ]);

        $prof->services()->sync(ClinicalService::pluck('id')->all());

        return $prof;
    }

    private function staffUser(string $role, ?string $professionalId): array
    {
        $user = User::create([
            'name' => 'Staff ' . $role,
            'email' => $role . '@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $user->forceFill([
            'role' => $role,
            'professional_id' => $professionalId,
        ])->save();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeBooking(string $id, string $zone, ?string $professionalId = null): ServiceRequest
    {
        $patient = User::create([
            'name' => 'Paciente ' . $id,
            'email' => $id . '@paciente.cl',
            'password' => bcrypt('password123'),
        ]);

        return ServiceRequest::create([
            'id' => $id,
            'user_id' => $patient->id,
            'service_id' => 'medico',
            'status' => 'accepted',
            'patient_type' => 'self',
            'address_text' => "Calle 1, $zone",
            'zone' => $zone,
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 1,
            'professional_id' => $professionalId,
        ]);
    }

    public function test_staff_endpoints_reject_patients_and_anonymous_callers(): void
    {
        $this->getJson('/api/staff/bookings')->assertStatus(401);

        $patient = User::create([
            'name' => 'Paciente',
            'email' => 'solo-paciente@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $token = $patient->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/staff/bookings')
            ->assertStatus(403);
    }

    public function test_professional_without_clinical_record_is_refused(): void
    {
        [, $token] = $this->staffUser('doctor_provider', null);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/staff/bookings')
            ->assertStatus(403);
    }

    public function test_professional_sees_own_zone_first(): void
    {
        $this->makeService();
        $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', 'prof_api');

        $this->makeBooking('req_far', 'Puente Alto');
        $this->makeBooking('req_near', 'Providencia');

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/staff/bookings')
            ->assertStatus(200);

        $bookings = $response->json();
        $this->assertCount(2, $bookings);

        // Own zone comes first, and the far one is flagged rather than hidden.
        $this->assertSame('req_near', $bookings[0]['id']);
        $this->assertFalse($bookings[0]['outside_zone']);
        $this->assertSame('req_far', $bookings[1]['id']);
        $this->assertTrue($bookings[1]['outside_zone']);
    }

    public function test_advancing_a_booking_claims_it_and_records_the_real_professional(): void
    {
        $this->makeService();
        $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', 'prof_api');
        $booking = $this->makeBooking('req_flow', 'Providencia');

        $headers = ['Authorization' => "Bearer $token"];

        // Primero se toma; avanzar el estado ya no asigna por si solo.
        $this->withHeaders($headers)
            ->postJson("/api/staff/bookings/{$booking->id}/claim")
            ->assertStatus(200);

        foreach (['en_camino', 'en_atencion', 'completed'] as $status) {
            $this->withHeaders($headers)
                ->postJson("/api/staff/bookings/{$booking->id}/status", ['status' => $status])
                ->assertStatus(200);
        }

        $fresh = ServiceRequest::find('req_flow');
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('prof_api', $fresh->professional_id);

        $record = \App\Models\PastService::first();
        $this->assertNotNull($record);
        $this->assertSame('prof_api', $record->professional_id);
        $this->assertStringContainsString('Dra. API', $record->professional);
    }

    public function test_cannot_go_off_duty_with_a_visit_in_progress(): void
    {
        $this->makeService();
        $this->makeProfessional();
        [, $token] = $this->staffUser('doctor_provider', 'prof_api');
        $this->makeBooking('req_busy', 'Providencia', 'prof_api');

        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson('/api/staff/duty', ['duty_status' => 'desconectado'])
            ->assertStatus(422);

        $this->assertSame('disponible', Professional::find('prof_api')->duty_status);
    }

    public function test_operations_panel_is_operator_only(): void
    {
        $this->makeService();
        $this->makeProfessional();

        [, $professionalToken] = $this->staffUser('doctor_provider', 'prof_api');
        $this->withHeaders(['Authorization' => "Bearer $professionalToken"])
            ->getJson('/api/staff/admin/metrics')
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();

        [, $operatorToken] = $this->staffUser('operator_admin', null);
        $this->withHeaders(['Authorization' => "Bearer $operatorToken"])
            ->getJson('/api/staff/admin/metrics')
            ->assertStatus(200)
            ->assertJsonStructure(['professionals_on_duty', 'open_requests', 'average_eta_minutes']);
    }
}
