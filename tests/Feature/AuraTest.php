<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Dependent;
use App\Models\SavedAddress;
use App\Models\SavedPaymentMethod;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test authentication rules: register/login errors and protected endpoints.
     */
    public function test_authentication_flows_and_unauthorized_access(): void
    {
        // 1. Missing fields in register -> 422
        $response = $this->postJson('/api/auth/register', []);
        $response->assertStatus(422);

        // 2. Bad credentials in login -> 422
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@aura.cl',
            'password' => 'wrongpassword'
        ]);
        $response->assertStatus(422);

        // 3. Register a valid user
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@aura.cl',
            'password' => 'password123'
        ]);
        $response->assertStatus(201)
                 ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $token = $response->json('token');

        // 4. Accessing protected endpoint without token -> 401
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);

        // 5. Accessing protected endpoint with token -> 200 (direct user object returned)
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->getJson('/api/auth/me');
        $response->assertStatus(200)
                 ->assertJsonPath('email', 'test@aura.cl');
    }

    /**
     * Test isolation between users.
     */
    public function test_user_data_isolation(): void
    {
        // Create User A and User B
        $userA = User::create([
            'name' => 'User A',
            'email' => 'usera@aura.cl',
            'password' => bcrypt('password123')
        ]);
        $tokenA = $userA->createToken('token_a')->plainTextToken;

        $userB = User::create([
            'name' => 'User B',
            'email' => 'userb@aura.cl',
            'password' => bcrypt('password123')
        ]);
        $tokenB = $userB->createToken('token_b')->plainTextToken;

        // User A creates a dependent and an address
        $depA = Dependent::create([
            'id' => 'dep_a_123',
            'user_id' => $userA->id,
            'name' => 'Dependent of A',
            'relationship' => 'Hijo',
            'age' => 8,
            'health_insurance' => 'Fonasa',
            'medical_conditions' => 'Ninguna'
        ]);

        $addrA = SavedAddress::create([
            'id' => 'addr_a_123',
            'user_id' => $userA->id,
            'label' => 'Casa A',
            'text' => 'Calle Falsa 123'
        ]);

        // User B lists dependents and addresses -> should be empty
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
                         ->getJson('/api/dependents');
        $response->assertStatus(200)->assertJsonCount(0);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
                         ->getJson('/api/addresses');
        $response->assertStatus(200)->assertJsonCount(0);

        // User B tries to delete User A's dependent -> should return 404
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
                         ->deleteJson("/api/dependents/{$depA->id}");
        $response->assertStatus(404);

        // User B tries to update User A's address -> should return 404
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
                         ->putJson("/api/addresses/{$addrA->id}", [
                             'label' => 'Hack label',
                             'text' => 'Hack text'
                         ]);
        $response->assertStatus(404);
    }

    /**
     * Test booking full lifecycle: create, simulate step, history, and cancel.
     */
    public function test_booking_lifecycle_and_cancellation(): void
    {
        $user = User::create([
            'name' => 'Lifecycle User',
            'email' => 'lifecycle@aura.cl',
            'password' => bcrypt('password123')
        ]);
        $token = $user->createToken('test_token')->plainTextToken;

        // Seed some services to satisfy DB constraints
        \Illuminate\Support\Facades\DB::table('clinical_services')->insert([
            'id' => 'medico',
            'title' => 'Médico a domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Servicio médico',
            'base_price' => 25000,
            'base_eta' => '30 min',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Ninguna',
            'placeholder_text' => 'Ingrese síntomas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Create a booking
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson('/api/bookings', [
                             'service_id' => 'medico',
                             'patient_type' => 'self',
                             'address_text' => 'Calle Principal 456',
                             'final_price' => 25000,
                             'eta_minutes' => 30,
                         ]);
        $response->assertStatus(201);
        $bookingId = $response->json('id');

        // Check active booking
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->getJson('/api/bookings/active');
        $response->assertStatus(200)->assertJsonPath('status', 'accepted');

        // 2. Advance booking (Simulate step 1 -> step 2)
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson("/api/bookings/{$bookingId}/simulate-step");
        $response->assertStatus(200)->assertJsonPath('current_step', 2)->assertJsonPath('status', 'en_camino');

        // 3. Advance booking (Simulate step 2 -> step 3)
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson("/api/bookings/{$bookingId}/simulate-step");
        $response->assertStatus(200)->assertJsonPath('current_step', 3)->assertJsonPath('status', 'en_atencion');

        // 4. Advance booking (Simulate step 3 -> step 4 / complete)
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson("/api/bookings/{$bookingId}/simulate-step");
        $response->assertStatus(200)->assertJsonPath('current_step', 4)->assertJsonPath('status', 'completed');

        // Check active booking -> should be null
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->getJson('/api/bookings/active');
        $response->assertStatus(200)->assertExactJson([]);

        // Check history -> should contain 1 completed booking
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->getJson('/api/history');
        $response->assertStatus(200)->assertJsonCount(1);

        // 5. Create another booking and test cancellation
        sleep(1);
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson('/api/bookings', [
                             'service_id' => 'medico',
                             'patient_type' => 'self',
                             'address_text' => 'Calle Principal 456',
                             'final_price' => 25000,
                             'eta_minutes' => 30,
                         ]);
        $response->assertStatus(201);
        $newBookingId = $response->json('id');

        // Cancel booking
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                         ->postJson("/api/bookings/{$newBookingId}/cancel");
        $response->assertStatus(200)->assertJsonPath('status', 'cancelled');
    }

    /**
     * Test social login with server-side verified Google id_tokens.
     */
    public function test_social_authentication_flows(): void
    {
        config(['services.google.client_id' => 'aura-client-id.apps.googleusercontent.com']);

        \Illuminate\Support\Facades\Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => \Illuminate\Support\Facades\Http::response([
                'aud' => 'aura-client-id.apps.googleusercontent.com',
                'sub' => 'google_123456',
                'email' => 'googleuser@aura.cl',
                'email_verified' => 'true',
                'name' => 'Google User',
            ]),
        ]);

        // 1. Register a new user via Google (identity comes from the verified token)
        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'credential' => 'valid-google-id-token',
        ]);
        $response->assertStatus(201)
                 ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
                 ->assertJsonPath('user.email', 'googleuser@aura.cl');

        $userId = $response->json('user.id');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $userId,
            'provider' => 'google',
            'provider_id' => 'google_123456',
        ]);

        // 2. Login again with Google -> should get 200 and same user
        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'credential' => 'valid-google-id-token',
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('user.id', $userId);
    }

    /**
     * Test that forged or foreign-audience tokens cannot authenticate.
     */
    public function test_social_login_rejects_invalid_tokens(): void
    {
        config(['services.google.client_id' => 'aura-client-id.apps.googleusercontent.com']);

        // Token issued for ANOTHER app (wrong audience) must be rejected
        \Illuminate\Support\Facades\Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => \Illuminate\Support\Facades\Http::response([
                'aud' => 'attacker-app.apps.googleusercontent.com',
                'sub' => 'google_999',
                'email' => 'victima@aura.cl',
                'email_verified' => 'true',
            ]),
        ]);

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'credential' => 'token-from-another-app',
        ])->assertStatus(401);

        // Client-supplied identity fields are ignored by validation
        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'email' => 'victima@aura.cl',
            'provider_id' => 'x',
            'name' => 'Atacante',
        ])->assertStatus(422);
    }

    /**
     * Test Facebook login with server-side verified access tokens.
     */
    public function test_facebook_login_verifies_token_server_side(): void
    {
        config([
            'services.facebook.app_id' => '123456',
            'services.facebook.app_secret' => 'fb-secret',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/debug_token*' => \Illuminate\Support\Facades\Http::response([
                'data' => ['is_valid' => true, 'app_id' => '123456', 'user_id' => 'fb_777'],
            ]),
            'graph.facebook.com/me*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'fb_777',
                'name' => 'FB User',
                'email' => 'fbuser@aura.cl',
            ]),
        ]);

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'facebook',
            'credential' => 'valid-fb-access-token',
        ]);
        $response->assertStatus(201)
                 ->assertJsonPath('user.email', 'fbuser@aura.cl');

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'facebook',
            'provider_id' => 'fb_777',
        ]);
    }

    /**
     * Test that a token issued for another Facebook app is rejected.
     */
    public function test_facebook_login_rejects_foreign_app_token(): void
    {
        config([
            'services.facebook.app_id' => '123456',
            'services.facebook.app_secret' => 'fb-secret',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/debug_token*' => \Illuminate\Support\Facades\Http::response([
                'data' => ['is_valid' => true, 'app_id' => '999999', 'user_id' => 'fb_777'],
            ]),
        ]);

        $this->postJson('/api/auth/social', [
            'provider' => 'facebook',
            'credential' => 'token-from-other-app',
        ])->assertStatus(401);
    }

    /**
     * Test that unconfigured providers are unavailable.
     */
    public function test_social_login_unavailable_when_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'credential' => 'whatever',
        ])->assertStatus(503);

        // Instagram is no longer an accepted provider
        $this->postJson('/api/auth/social', [
            'provider' => 'instagram',
            'credential' => 'whatever',
        ])->assertStatus(422);
    }
}
