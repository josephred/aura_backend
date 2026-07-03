<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserToken(string $email): string
    {
        $user = User::create([
            'name' => 'Test',
            'email' => $email,
            'password' => 'password123',
        ]);

        return $user->createToken('test')->plainTextToken;
    }

    public function test_device_token_registration_requires_auth(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'fcm_abc'])->assertStatus(401);
    }

    public function test_device_token_can_be_registered_and_reassigned(): void
    {
        $tokenA = $this->makeUserToken('a@aura.cl');
        $tokenB = $this->makeUserToken('b@aura.cl');

        // User A registers a device
        $this->withToken($tokenA)
            ->postJson('/api/device-tokens', ['token' => 'fcm_abc', 'platform' => 'android'])
            ->assertStatus(201);
        $this->assertDatabaseCount('device_tokens', 1);

        // Same device logs into user B: token moves, no duplicate
        app('auth')->forgetGuards();
        $this->withToken($tokenB)
            ->postJson('/api/device-tokens', ['token' => 'fcm_abc'])
            ->assertStatus(201);
        $this->assertDatabaseCount('device_tokens', 1);

        $userB = User::where('email', 'b@aura.cl')->first();
        $this->assertDatabaseHas('device_tokens', ['token' => 'fcm_abc', 'user_id' => $userB->id]);
    }

    public function test_device_token_can_be_removed(): void
    {
        $token = $this->makeUserToken('c@aura.cl');

        $this->withToken($token)->postJson('/api/device-tokens', ['token' => 'fcm_xyz']);
        $this->withToken($token)
            ->deleteJson('/api/device-tokens', ['token' => 'fcm_xyz'])
            ->assertStatus(200);

        $this->assertDatabaseCount('device_tokens', 0);
    }
}
