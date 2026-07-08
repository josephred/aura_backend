<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessionalAccount(
        string $id,
        string $email,
        string $role = 'professional',
        bool $active = true,
    ): Professional {
        return Professional::create([
            'id' => $id,
            'name' => "Prof $id",
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => $active,
            'email' => $email,
            'password' => Hash::make('clave-segura-123'),
            'role' => $role,
        ]);
    }

    private function makeAppointmentFor(string $professionalId, string $id): Appointment
    {
        $user = User::firstOrCreate(
            ['email' => 'paciente@aura.cl'],
            ['name' => 'Paciente', 'password' => bcrypt('password123')],
        );

        return Appointment::create([
            'id' => $id,
            'user_id' => $user->id,
            'professional_id' => $professionalId,
            'scheduled_at' => now()->addMinutes(10),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'video',
            'price' => 20000,
        ]);
    }

    public function test_doctor_portal_requires_staff_session(): void
    {
        $this->get('/doctor')->assertRedirect('/doctor/login');
        $this->getJson('/doctor/api/bookings')->assertStatus(401);
        $this->getJson('/doctor/api/appointments')->assertStatus(401);
        $this->postJson('/doctor/api/bookings/req_1/status', ['status' => 'completed'])->assertStatus(401);
    }

    public function test_staff_login_with_email_and_password(): void
    {
        $this->makeProfessionalAccount('prof_a', 'doctora@aura.cl');

        $this->get('/doctor/login')->assertStatus(200);

        // Wrong password -> rejected
        $this->from('/doctor/login')
            ->post('/doctor/login', ['email' => 'doctora@aura.cl', 'password' => 'incorrecta'])
            ->assertRedirect('/doctor/login')
            ->assertSessionHasErrors('email');
        $this->get('/doctor')->assertRedirect('/doctor/login');

        // Unknown email -> rejected
        $this->from('/doctor/login')
            ->post('/doctor/login', ['email' => 'nadie@aura.cl', 'password' => 'clave-segura-123'])
            ->assertSessionHasErrors('email');

        // Valid credentials -> session with identity
        $this->post('/doctor/login', ['email' => 'doctora@aura.cl', 'password' => 'clave-segura-123'])
            ->assertRedirect('/doctor')
            ->assertSessionHas('staff_professional_id', 'prof_a')
            ->assertSessionHas('staff_role', 'professional');
        $this->get('/doctor')->assertStatus(200);
        $this->assertNotNull(Professional::find('prof_a')->last_login_at);

        // Logout locks it again
        $this->post('/doctor/logout')->assertRedirect('/doctor/login');
        $this->get('/doctor')->assertRedirect('/doctor/login');
    }

    public function test_professional_without_password_cannot_login(): void
    {
        Professional::create([
            'id' => 'prof_nopass',
            'name' => 'Sin Cuenta',
            'specialty' => 'Enfermería',
            'consultation_price' => 15000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => 'sincuenta@aura.cl',
        ]);

        $this->from('/doctor/login')
            ->post('/doctor/login', ['email' => 'sincuenta@aura.cl', 'password' => 'lo-que-sea'])
            ->assertSessionHasErrors('email');
    }

    public function test_professionals_only_see_their_own_agenda(): void
    {
        $this->makeProfessionalAccount('prof_a', 'a@aura.cl');
        $this->makeProfessionalAccount('prof_b', 'b@aura.cl');
        $this->makeAppointmentFor('prof_a', 'apt_of_a');
        $this->makeAppointmentFor('prof_b', 'apt_of_b');

        $this->post('/doctor/login', ['email' => 'a@aura.cl', 'password' => 'clave-segura-123']);

        // Agenda only lists own appointments
        $this->getJson('/doctor/api/appointments')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'apt_of_a');

        // Cannot touch a foreign appointment nor its video call
        $this->postJson('/doctor/api/appointments/apt_of_b/status', ['status' => 'completed'])
            ->assertStatus(404);
        $this->getJson('/doctor/api/appointments/apt_of_b/webrtc-config')->assertStatus(404);
        $this->get('/doctor/agenda/call/apt_of_b')->assertStatus(404);

        // Own appointment works
        $this->getJson('/doctor/api/appointments/apt_of_a/webrtc-config')->assertStatus(200);

        // Cannot edit another professional's schedule
        $this->postJson('/doctor/api/professionals/prof_b/schedules', [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertStatus(403);
        $this->postJson('/doctor/api/professionals/prof_a/schedules', [
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertStatus(201);
    }

    public function test_admin_sees_everything_and_manages_accounts(): void
    {
        $this->makeProfessionalAccount('prof_a', 'a@aura.cl');
        $this->makeProfessionalAccount('staff_admin', 'admin@aura.cl', 'admin', false);
        $this->makeAppointmentFor('prof_a', 'apt_of_a');

        $this->post('/doctor/login', ['email' => 'admin@aura.cl', 'password' => 'clave-segura-123']);

        // Admin sees all appointments and can manage any schedule
        $this->getJson('/doctor/api/appointments')->assertStatus(200)->assertJsonCount(1);
        $this->postJson('/doctor/api/professionals/prof_a/schedules', [
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertStatus(201);

        // Account management: create credentials, password generated
        $response = $this->postJson('/doctor/api/professionals/prof_a/account', [
            'email' => 'nueva@aura.cl',
        ]);
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('generated_password'));
        $this->assertEquals('nueva@aura.cl', Professional::find('prof_a')->email);
    }

    public function test_account_management_is_admin_only(): void
    {
        $this->makeProfessionalAccount('prof_a', 'a@aura.cl');
        $this->makeProfessionalAccount('prof_b', 'b@aura.cl');

        $this->post('/doctor/login', ['email' => 'a@aura.cl', 'password' => 'clave-segura-123']);

        $this->getJson('/doctor/api/accounts')->assertStatus(403);
        $this->postJson('/doctor/api/professionals/prof_b/account', ['email' => 'x@aura.cl'])
            ->assertStatus(403);
    }

    public function test_public_catalog_does_not_leak_credentials(): void
    {
        $this->makeProfessionalAccount('prof_a', 'a@aura.cl');

        $response = $this->getJson('/api/professionals');
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('password', $response->json('0'));
        $this->assertArrayNotHasKey('email', $response->json('0'));
    }
}
