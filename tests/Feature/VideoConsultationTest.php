<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoConsultationTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessional(): Professional
    {
        $professional = Professional::create([
            'id' => 'prof_video',
            'name' => 'Dra. Video',
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
        ]);

        foreach (range(1, 7) as $day) {
            ProfessionalSchedule::create([
                'professional_id' => $professional->id,
                'day_of_week' => $day,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]);
        }

        return $professional;
    }

    private function makeUser(string $email = 'patient@aura.cl'): array
    {
        $user = User::create([
            'name' => 'Video Patient',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    private function fakeDaily(): void
    {
        config(['services.daily.api_key' => 'daily-test-key']);

        Http::fake([
            'api.daily.co/v1/rooms' => Http::response([
                'name' => 'apt_room',
                'url' => 'https://aura.daily.co/apt_room',
            ]),
            'api.daily.co/v1/meeting-tokens' => Http::response([
                'token' => 'daily-jwt-token',
            ]),
        ]);
    }

    public function test_video_appointment_creates_private_room_on_confirm(): void
    {
        $this->fakeDaily();
        $this->makeProfessional();
        [, $token] = $this->makeUser();

        // No payment gateway: confirms immediately and provisions the room
        $response = $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_video',
            'scheduled_at' => now()->addDays(3)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'type' => 'video',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('has_video_room', true);

        $appointment = Appointment::find($response->json('id'));
        $this->assertEquals('apt_room', $appointment->video_room_name);
        $this->assertEquals('https://aura.daily.co/apt_room', $appointment->video_room_url);

        // The room was requested as private
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rooms')
                ? $request['privacy'] === 'private'
                : true;
        });
    }

    public function test_video_appointment_without_daily_still_confirms(): void
    {
        config(['services.daily.api_key' => null]);
        $this->makeProfessional();
        [, $token] = $this->makeUser();

        $response = $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_video',
            'scheduled_at' => now()->addDays(3)->setTime(9, 30)->format('Y-m-d H:i:s'),
            'type' => 'video',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('has_video_room', false);
    }

    public function test_patient_join_respects_time_window_and_ownership(): void
    {
        $this->fakeDaily();
        $professional = $this->makeProfessional();
        [$userA, $tokenA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');

        // Appointment starting in 10 minutes (inside the 15-minute window)
        $joinable = Appointment::create([
            'id' => 'apt_joinable',
            'user_id' => $userA->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addMinutes(10),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'video',
            'video_room_name' => 'apt_room',
            'video_room_url' => 'https://aura.daily.co/apt_room',
            'price' => 20000,
        ]);

        // Appointment in 5 hours (outside the window)
        $tooEarly = Appointment::create([
            'id' => 'apt_early',
            'user_id' => $userA->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addHours(5),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'video',
            'video_room_name' => 'apt_room2',
            'video_room_url' => 'https://aura.daily.co/apt_room2',
            'price' => 20000,
        ]);

        // Owner within the window gets a tokenized join URL
        $this->withToken($tokenA)->getJson("/api/appointments/{$joinable->id}/video-join")
            ->assertStatus(200)
            ->assertJsonPath('join_url', 'https://aura.daily.co/apt_room?t=daily-jwt-token');

        // Too early is rejected
        $this->withToken($tokenA)->getJson("/api/appointments/{$tooEarly->id}/video-join")
            ->assertStatus(422);

        // Another user cannot join someone else's consultation
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->getJson("/api/appointments/{$joinable->id}/video-join")
            ->assertStatus(404);
    }

    public function test_presencial_appointment_has_no_video_join(): void
    {
        $this->fakeDaily();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();

        $appointment = Appointment::create([
            'id' => 'apt_presencial',
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addMinutes(10),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'presencial',
            'price' => 20000,
        ]);

        $this->withToken($token)->getJson("/api/appointments/{$appointment->id}/video-join")
            ->assertStatus(422);
    }

    public function test_staff_join_gets_owner_token(): void
    {
        $this->fakeDaily();
        config(['services.doctor_portal.access_key' => 'staff-key']);
        $professional = $this->makeProfessional();
        [$user] = $this->makeUser();

        $appointment = Appointment::create([
            'id' => 'apt_staff',
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addMinutes(5),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'video',
            'video_room_name' => 'apt_room',
            'video_room_url' => 'https://aura.daily.co/apt_room',
            'price' => 20000,
        ]);

        // Authenticate into the staff portal session
        $this->post('/doctor/login', ['access_key' => 'staff-key']);

        $this->getJson("/doctor/api/appointments/{$appointment->id}/video-join")
            ->assertStatus(200)
            ->assertJsonPath('join_url', 'https://aura.daily.co/apt_room?t=daily-jwt-token');

        // The token was requested with owner privileges
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'meeting-tokens')
                ? $request['properties']['is_owner'] === true
                : true;
        });
    }
}
