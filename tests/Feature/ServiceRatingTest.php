<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\Professional;
use App\Models\ServiceRating;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-09: Sistema de calificación de atenciones clínicas.
 */
class ServiceRatingTest extends TestCase
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

    private function makeProfessional(string $id = 'prof_rating'): Professional
    {
        return Professional::create([
            'id' => $id,
            'name' => 'Dra. Andrea Morales',
            'specialty' => 'Medicina General',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'duty_status' => 'disponible',
            'coverage_zones' => 'Providencia',
            'rating_avg' => null,
            'rating_count' => 0,
        ]);
    }

    private function makeUser(string $email = 'paciente@aura.cl'): array
    {
        $user = User::create([
            'name' => 'Paciente Aura',
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeBooking(string $id, User $user, string $status = 'completed', ?string $professionalId = 'prof_rating'): ServiceRequest
    {
        return ServiceRequest::create([
            'id' => $id,
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => $status,
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'zone' => 'Providencia',
            'payment_method' => 'mercadopago',
            'final_price' => 25000,
            'start_time' => '10:00',
            'eta_minutes' => 30,
            'current_step' => 3,
            'professional_id' => $professionalId,
        ]);
    }

    public function test_user_can_rate_completed_booking(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $booking = $this->makeBooking('req_completed_1', $user, 'completed', $professional->id);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", [
                'rating' => 5,
                'feedback' => 'Excelente atención y puntualidad.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('rating.rating', 5)
            ->assertJsonPath('rating.feedback', 'Excelente atención y puntualidad.');

        $this->assertDatabaseHas('service_ratings', [
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'rating' => 5,
            'feedback' => 'Excelente atención y puntualidad.',
        ]);

        $professional->refresh();
        $this->assertEquals(5.0, $professional->rating_avg);
        $this->assertSame(1, $professional->rating_count);
    }

    public function test_cannot_rate_incomplete_or_cancelled_booking(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $booking = $this->makeBooking('req_in_progress', $user, 'en_camino', $professional->id);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", [
                'rating' => 5,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Solo se pueden calificar atenciones finalizadas.');

        $this->assertDatabaseCount('service_ratings', 0);
    }

    public function test_cannot_rate_another_users_booking(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$owner] = $this->makeUser('owner@aura.cl');
        [, $hackerToken] = $this->makeUser('hacker@aura.cl');
        $booking = $this->makeBooking('req_owner', $owner, 'completed', $professional->id);

        $response = $this->withHeaders(['Authorization' => "Bearer $hackerToken"])
            ->postJson("/api/bookings/{$booking->id}/rating", [
                'rating' => 1,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('service_ratings', 0);
    }

    public function test_rating_is_idempotent_and_updates_without_duplication(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $booking = $this->makeBooking('req_idempotent', $user, 'completed', $professional->id);

        // First rating: 4 stars
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", [
                'stars' => 4,
                'comment' => 'Muy bien.',
            ])->assertStatus(200);

        $professional->refresh();
        $this->assertEquals(4.0, $professional->rating_avg);
        $this->assertSame(1, $professional->rating_count);
        $this->assertDatabaseCount('service_ratings', 1);

        // Second rating on same booking: updated to 5 stars
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", [
                'rating' => 5,
                'feedback' => 'Cambié a excelente.',
            ])->assertStatus(200);

        $professional->refresh();
        $this->assertEquals(5.0, $professional->rating_avg);
        $this->assertSame(1, $professional->rating_count);
        $this->assertDatabaseCount('service_ratings', 1);
    }

    public function test_average_calculated_accurately_across_multiple_ratings(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user1, $token1] = $this->makeUser('user1@aura.cl');
        [$user2, $token2] = $this->makeUser('user2@aura.cl');

        $booking1 = $this->makeBooking('req_multi_1', $user1, 'completed', $professional->id);
        $booking2 = $this->makeBooking('req_multi_2', $user2, 'completed', $professional->id);

        $response1 = $this->withHeaders(['Authorization' => "Bearer $token1"])
            ->postJson("/api/bookings/{$booking1->id}/rating", ['rating' => 4]);
        $response1->assertStatus(200);

        app('auth')->forgetGuards();

        $response2 = $this->withHeaders(['Authorization' => "Bearer $token2"])
            ->postJson("/api/bookings/{$booking2->id}/rating", ['rating' => 5]);
        $response2->assertStatus(200);

        $this->assertDatabaseCount('service_ratings', 2);

        $professional->refresh();
        $this->assertEquals(4.5, $professional->rating_avg);
        $this->assertSame(2, $professional->rating_count);
    }

    public function test_rating_bounds_validation(): void
    {
        $this->makeService();
        $professional = $this->makeProfessional();
        [$user, $token] = $this->makeUser();
        $booking = $this->makeBooking('req_bounds', $user, 'completed', $professional->id);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", ['rating' => 0])
            ->assertStatus(422);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/bookings/{$booking->id}/rating", ['rating' => 6])
            ->assertStatus(422);
    }
}
