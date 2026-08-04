<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B.3 — ficha del profesional que el paciente puede consultar.
 *
 * Lo que se protege aquí es que la app no muestre datos que nadie verificó:
 * un promedio de cinco estrellas sin evaluaciones, o credenciales de un
 * profesional filtradas al catálogo público.
 */
class ProfessionalProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessional(array $overrides = []): Professional
    {
        return Professional::create(array_merge([
            'id' => 'prof_ficha',
            'name' => 'Dra. Camila Rivera',
            'specialty' => 'Medicina Interna',
            'bio' => 'Internista con foco en adultos mayores.',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'phone' => '+56 9 1111 2222',
            'registration_number' => 'SIS-118472',
            'years_of_experience' => 12,
        ], $overrides));
    }

    private function makeService(): void
    {
        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Atención Médica',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Consulta médica a domicilio.',
            'base_price' => 40000,
            'base_eta' => '45 - 60',
            'requires_prescription' => false,
            'icon_name' => 'UserRoundPlus',
            'warning_info' => 'Servicio no apto para urgencias de riesgo vital.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    /** @return array{0:User,1:string} */
    private function makeUser(): array
    {
        $user = User::create([
            'name' => 'Paciente',
            'email' => 'ficha@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    private function makeRequestFor(Professional $professional, User $user): ServiceRequest
    {
        return ServiceRequest::create([
            'id' => 'req_ficha',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'en_camino',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'payment_method' => 'mercadopago',
            'final_price' => 40000,
            'start_time' => '10:00',
            'eta_minutes' => 45,
            'current_step' => 2,
            'professional_id' => $professional->id,
        ]);
    }

    public function test_assigned_professional_carries_the_full_profile(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $this->makeRequestFor($professional, $user);

        $this->withToken($token)->getJson('/api/bookings/active')
            ->assertStatus(200)
            ->assertJsonPath('assigned_professional.name', 'Dra. Camila Rivera')
            ->assertJsonPath('assigned_professional.registration_number', 'SIS-118472')
            ->assertJsonPath('assigned_professional.years_of_experience', 12)
            ->assertJsonPath('assigned_professional.bio', 'Internista con foco en adultos mayores.')
            // El teléfono solo lo ve quien está siendo atendido.
            ->assertJsonPath('assigned_professional.phone', '+56 9 1111 2222');
    }

    public function test_rating_is_null_until_somebody_actually_rates(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $this->makeRequestFor($professional, $user);

        // La columna arranca en 5.00: sin evaluaciones no debe publicarse.
        $this->withToken($token)->getJson('/api/bookings/active')
            ->assertStatus(200)
            ->assertJsonPath('assigned_professional.rating_avg', null)
            ->assertJsonPath('assigned_professional.rating_count', 0);

        $this->getJson('/api/professionals')
            ->assertStatus(200)
            ->assertJsonPath('0.rating_avg', null);

        $professional->update(['rating_avg' => 4.7, 'rating_count' => 12]);

        $this->getJson('/api/professionals')
            ->assertStatus(200)
            ->assertJsonPath('0.rating_avg', 4.7)
            ->assertJsonPath('0.rating_count', 12);
    }

    public function test_public_catalogue_publishes_the_profile_but_not_the_credentials(): void
    {
        $this->makeProfessional([
            'email' => 'camila@aura.cl',
            'password' => bcrypt('clave-segura-123'),
            'commission_bps' => 800,
        ]);

        $response = $this->getJson('/api/professionals')->assertStatus(200);

        $response->assertJsonPath('0.registration_number', 'SIS-118472')
            ->assertJsonPath('0.years_of_experience', 12);

        $professional = $response->json()[0];

        foreach (['email', 'password', 'role', 'phone', 'commission_bps', 'duty_status'] as $secret) {
            // Llaves obligatorias: sin ellas, PHP lee «$secret» como la
            // variable `$secret»` —los bytes altos son válidos en un nombre—
            // y revienta con Undefined variable.
            $this->assertArrayNotHasKey($secret, $professional, "Se filtró «{$secret}» al catálogo público.");
        }
    }
}
