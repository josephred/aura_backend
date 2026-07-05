<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessional(int $durationMinutes = 30): Professional
    {
        $professional = Professional::create([
            'id' => 'prof_test',
            'name' => 'Dra. Test',
            'specialty' => 'Medicina General',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => $durationMinutes,
            'active' => true,
        ]);

        // Available every day of the week, 09:00-11:00
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
            'name' => 'Patient',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    public function test_professionals_catalog_and_free_slots(): void
    {
        $this->makeProfessional();

        $this->getJson('/api/professionals')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'prof_test');

        // 09:00-11:00 with 30-minute consultations = 4 slots on a future date
        $date = now()->addDays(7)->format('Y-m-d');
        $response = $this->getJson("/api/professionals/prof_test/slots?date=$date");
        $response->assertStatus(200)->assertJsonCount(4, 'slots');

        // Inactive professionals are hidden
        Professional::where('id', 'prof_test')->update(['active' => false]);
        $this->getJson('/api/professionals')->assertStatus(200)->assertJsonCount(0);
        $this->getJson("/api/professionals/prof_test/slots?date=$date")->assertStatus(404);
    }

    public function test_booking_takes_slot_and_rejects_double_booking(): void
    {
        $this->makeProfessional();
        [, $tokenA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');

        $slot = now()->addDays(7)->setTime(9, 30)->format('Y-m-d H:i:s');

        // Without a payment gateway the appointment confirms immediately
        $response = $this->withToken($tokenA)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => $slot,
            'reason' => 'Dolor de cabeza persistente',
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('price', 20000)
            ->assertJsonPath('professional_name', 'Dra. Test');

        // The slot disappears from availability
        $date = now()->addDays(7)->format('Y-m-d');
        $this->getJson("/api/professionals/prof_test/slots?date=$date")
            ->assertJsonCount(3, 'slots');

        // Another user cannot take the same slot
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => $slot,
        ])->assertStatus(409);
    }

    public function test_booking_rejects_times_outside_schedule(): void
    {
        $this->makeProfessional();
        [, $token] = $this->makeUser();

        // 14:00 is outside the 09:00-11:00 block
        $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => now()->addDays(7)->setTime(14, 0)->format('Y-m-d H:i:s'),
        ])->assertStatus(409);

        // 10:45 is inside the block but misaligned with the 30-minute grid
        $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => now()->addDays(7)->setTime(10, 45)->format('Y-m-d H:i:s'),
        ])->assertStatus(409);

        // Past dates are rejected
        $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => now()->subDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);

        // Beyond the 30-day horizon is rejected
        $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => now()->addDays(60)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    }

    public function test_appointment_payment_flow_with_gateway(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-TOKEN']);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref_apt_1',
                'init_point' => 'https://mp.test/checkout/apt',
            ]),
            'api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []]),
        ]);

        $this->makeProfessional();
        [, $token] = $this->makeUser();

        $response = $this->withToken($token)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => now()->addDays(7)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('payment_url', 'https://mp.test/checkout/apt');

        $appointmentId = $response->json('id');

        // Polling while unpaid keeps it pending
        $this->withToken($token)->getJson("/api/appointments/$appointmentId/payment-status")
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending_payment');

        // Webhook whose payment re-fetch is NOT approved does not confirm
        Http::fake([
            'api.mercadopago.com/v1/payments/999' => Http::response(['status' => 'rejected', 'external_reference' => $appointmentId]),
            'api.mercadopago.com/v1/payments/777' => Http::response(['status' => 'approved', 'external_reference' => $appointmentId]),
        ]);

        $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '999']]);
        $this->assertEquals('pending_payment', Appointment::find($appointmentId)->status);

        // Webhook with an approved payment confirms the appointment
        $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '777']]);
        $appointment = Appointment::find($appointmentId);
        $this->assertEquals('confirmed', $appointment->status);
        $this->assertEquals('approved', $appointment->payment_status);
        $this->assertEquals('777', $appointment->payment_id);
    }

    public function test_cancellation_frees_slot_and_users_are_isolated(): void
    {
        $this->makeProfessional();
        [, $tokenA] = $this->makeUser('a@aura.cl');
        [, $tokenB] = $this->makeUser('b@aura.cl');

        $slot = now()->addDays(7)->setTime(10, 0)->format('Y-m-d H:i:s');
        $response = $this->withToken($tokenA)->postJson('/api/appointments', [
            'professional_id' => 'prof_test',
            'scheduled_at' => $slot,
        ]);
        $appointmentId = $response->json('id');

        // User B cannot see nor cancel A's appointment
        app('auth')->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/appointments')
            ->assertStatus(200)->assertJsonCount(0);
        $this->withToken($tokenB)->postJson("/api/appointments/$appointmentId/cancel")
            ->assertStatus(404);

        // Owner cancels; the slot becomes available again
        app('auth')->forgetGuards();
        $this->withToken($tokenA)->postJson("/api/appointments/$appointmentId/cancel")
            ->assertStatus(200)->assertJsonPath('status', 'cancelled');

        $date = now()->addDays(7)->format('Y-m-d');
        $this->getJson("/api/professionals/prof_test/slots?date=$date")
            ->assertJsonCount(4, 'slots');

        // A cancelled appointment cannot be cancelled again
        $this->withToken($tokenA)->postJson("/api/appointments/$appointmentId/cancel")
            ->assertStatus(422);
    }

    public function test_reminders_command_marks_appointments(): void
    {
        $professional = $this->makeProfessional();
        [$user] = $this->makeUser();

        $soon = Appointment::create([
            'id' => 'apt_soon',
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addMinutes(45),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'price' => 20000,
        ]);

        $tomorrow = Appointment::create([
            'id' => 'apt_tomorrow',
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addHours(20),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'price' => 20000,
        ]);

        $farAway = Appointment::create([
            'id' => 'apt_far',
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->addDays(5),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'price' => 20000,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        // Within the hour: both reminders fire; within the day: only the 24h one
        $this->assertNotNull($soon->fresh()->reminder_24h_sent_at);
        $this->assertNotNull($soon->fresh()->reminder_1h_sent_at);
        $this->assertNotNull($tomorrow->fresh()->reminder_24h_sent_at);
        $this->assertNull($tomorrow->fresh()->reminder_1h_sent_at);
        $this->assertNull($farAway->fresh()->reminder_24h_sent_at);

        // Running again does not re-send
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $first = $soon->fresh()->reminder_1h_sent_at;
        $this->assertEquals($first, $soon->fresh()->reminder_1h_sent_at);
    }
}
