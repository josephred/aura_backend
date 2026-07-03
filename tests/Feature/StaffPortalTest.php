<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPortalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the doctor portal is inaccessible without a staff session.
     */
    public function test_doctor_portal_requires_staff_session(): void
    {
        config(['services.doctor_portal.access_key' => 'secret-key']);

        // Dashboard redirects to login
        $this->get('/doctor')->assertRedirect('/doctor/login');

        // JSON API endpoints return 401
        $this->getJson('/doctor/api/bookings')->assertStatus(401);
        $this->postJson('/doctor/api/bookings/req_1/status', ['status' => 'completed'])->assertStatus(401);
        $this->getJson('/doctor/api/bookings/req_1/messages')->assertStatus(401);
        $this->postJson('/doctor/api/bookings/req_1/messages', ['text' => 'hola'])->assertStatus(401);
    }

    /**
     * Test staff login with wrong and right access keys.
     */
    public function test_staff_login_flow(): void
    {
        config(['services.doctor_portal.access_key' => 'secret-key']);

        // Login page renders
        $this->get('/doctor/login')->assertStatus(200);

        // Wrong key -> back with error, still locked out
        $this->from('/doctor/login')
            ->post('/doctor/login', ['access_key' => 'wrong'])
            ->assertRedirect('/doctor/login')
            ->assertSessionHasErrors('access_key');
        $this->get('/doctor')->assertRedirect('/doctor/login');

        // Right key -> session started, portal accessible
        $this->post('/doctor/login', ['access_key' => 'secret-key'])
            ->assertRedirect('/doctor');
        $this->get('/doctor')->assertStatus(200);
        $this->getJson('/doctor/api/bookings')->assertStatus(200);

        // Logout locks it again
        $this->post('/doctor/logout')->assertRedirect('/doctor/login');
        $this->get('/doctor')->assertRedirect('/doctor/login');
    }

    /**
     * Test that login is rejected when no access key is configured.
     */
    public function test_login_rejected_when_key_not_configured(): void
    {
        config(['services.doctor_portal.access_key' => null]);

        $this->from('/doctor/login')
            ->post('/doctor/login', ['access_key' => 'anything'])
            ->assertRedirect('/doctor/login')
            ->assertSessionHasErrors('access_key');
    }
}
