<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\User;
use App\Models\VideoSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function makeVideoAppointment(User $user, string $id, \DateTimeInterface $when): Appointment
    {
        return Appointment::create([
            'id' => $id,
            'user_id' => $user->id,
            'professional_id' => 'prof_video',
            'scheduled_at' => $when,
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'video',
            'price' => 20000,
        ]);
    }

    public function test_video_appointment_confirms_without_external_services(): void
    {
        $this->makeProfessional();
        [, $token] = $this->makeUser();

        $response = $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_video',
            'scheduled_at' => now()->addDays(3)->setTime(9, 0)->format('Y-m-d H:i:s'),
            'type' => 'video',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('type', 'video');
    }

    public function test_patient_join_gives_ice_servers_within_window(): void
    {
        $this->makeProfessional();
        [$userA, $tokenA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');

        $joinable = $this->makeVideoAppointment($userA, 'apt_joinable', now()->addMinutes(10));
        $tooEarly = $this->makeVideoAppointment($userA, 'apt_early', now()->addHours(5));

        // Within the window: role + default STUN servers
        $response = $this->withToken($tokenA)->getJson("/api/appointments/{$joinable->id}/video-join");
        $response->assertStatus(200)->assertJsonPath('role', 'patient');
        $this->assertNotEmpty($response->json('ice_servers'));

        // Outside the window
        $this->withToken($tokenA)->getJson("/api/appointments/{$tooEarly->id}/video-join")
            ->assertStatus(422);

        // Another user cannot join someone else's consultation
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->getJson("/api/appointments/{$joinable->id}/video-join")
            ->assertStatus(404);
    }

    public function test_presencial_appointment_has_no_video_join(): void
    {
        $this->makeProfessional();
        [$user, $token] = $this->makeUser();

        $appointment = Appointment::create([
            'id' => 'apt_presencial',
            'user_id' => $user->id,
            'professional_id' => 'prof_video',
            'scheduled_at' => now()->addMinutes(10),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'type' => 'presencial',
            'price' => 20000,
        ]);

        $this->withToken($token)->getJson("/api/appointments/{$appointment->id}/video-join")
            ->assertStatus(422);
    }

    public function test_signaling_roundtrip_between_staff_and_patient(): void
    {
        config(['services.doctor_portal.access_key' => 'staff-key']);
        $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $appointment = $this->makeVideoAppointment($user, 'apt_call', now()->addMinutes(5));

        // A leftover signal from an older session
        VideoSignal::create([
            'appointment_id' => $appointment->id,
            'sender' => 'patient',
            'type' => 'candidate',
            'payload' => '{"candidate":"stale"}',
        ]);

        // Staff logs in and posts an offer: the old session is wiped
        $this->post('/doctor/login', ['access_key' => 'staff-key']);
        $offerResponse = $this->postJson("/doctor/api/appointments/{$appointment->id}/video-signals", [
            'type' => 'offer',
            'payload' => ['sdp' => 'v=0 staff-offer'],
        ]);
        $offerResponse->assertStatus(201);
        $this->assertEquals(1, VideoSignal::where('appointment_id', $appointment->id)->count());

        // Patient polls and receives only the staff offer
        $signals = $this->withToken($token)
            ->getJson("/api/appointments/{$appointment->id}/video-signals?after=0");
        $signals->assertStatus(200)->assertJsonCount(1, 'signals')
            ->assertJsonPath('signals.0.type', 'offer')
            ->assertJsonPath('signals.0.payload.sdp', 'v=0 staff-offer');

        $offerId = $signals->json('signals.0.id');

        // Patient answers and trickles a candidate
        $this->withToken($token)->postJson("/api/appointments/{$appointment->id}/video-signals", [
            'type' => 'answer',
            'payload' => ['sdp' => 'v=0 patient-answer'],
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/appointments/{$appointment->id}/video-signals", [
            'type' => 'candidate',
            'payload' => ['candidate' => 'cand-1', 'sdpMid' => '0', 'sdpMLineIndex' => 0],
        ])->assertStatus(201);

        // Staff receives both, in order, without its own offer echoed back
        $staffInbox = $this->getJson("/doctor/api/appointments/{$appointment->id}/video-signals?after=$offerId");
        $staffInbox->assertStatus(200)->assertJsonCount(2, 'signals')
            ->assertJsonPath('signals.0.type', 'answer')
            ->assertJsonPath('signals.1.type', 'candidate')
            ->assertJsonPath('signals.1.payload.candidate', 'cand-1');

        // Patient polling after the offer id sees nothing new from staff
        $this->withToken($token)
            ->getJson("/api/appointments/{$appointment->id}/video-signals?after=$offerId")
            ->assertJsonCount(0, 'signals');
    }

    public function test_patient_cannot_signal_foreign_appointments(): void
    {
        $this->makeProfessional();
        [$userA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');
        $appointment = $this->makeVideoAppointment($userA, 'apt_foreign', now()->addMinutes(5));

        $this->withToken($tokenB)->postJson("/api/appointments/{$appointment->id}/video-signals", [
            'type' => 'ready',
        ])->assertStatus(404);

        $this->withToken($tokenB)
            ->getJson("/api/appointments/{$appointment->id}/video-signals")
            ->assertStatus(404);
    }

    public function test_repeated_ready_signals_are_deduplicated(): void
    {
        $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $appointment = $this->makeVideoAppointment($user, 'apt_ready', now()->addMinutes(5));

        $this->withToken($token)->postJson("/api/appointments/{$appointment->id}/video-signals", ['type' => 'ready'])
            ->assertStatus(201);
        $this->withToken($token)->postJson("/api/appointments/{$appointment->id}/video-signals", ['type' => 'ready'])
            ->assertStatus(201);

        $this->assertEquals(1, VideoSignal::where('appointment_id', $appointment->id)
            ->where('type', 'ready')->count());
    }
}
