<?php

namespace Tests\Feature;

use App\Models\Dependent;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * REQ-14: Tests de calendario de vacunas y alertas por edad.
 */
class VaccineAgeAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependent_calculates_age_months_from_birth_date(): void
    {
        $user = User::create([
            'name' => 'Padre Test',
            'email' => 'padre@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        $dependent = Dependent::create([
            'id' => 'dep_baby_1',
            'user_id' => $user->id,
            'name' => 'Lactante 4 Meses',
            'relationship' => 'Hijo/a',
            'age' => 0,
            'birth_date' => now()->subMonths(4)->toDateString(),
            'health_insurance' => 'Fonasa',
        ]);

        $this->assertEquals(4, $dependent->age_months);
    }

    public function test_dependent_controller_accepts_birth_date_and_calculates_years(): void
    {
        $user = User::create([
            'name' => 'Madre Test',
            'email' => 'madre@aura.cl',
            'password' => bcrypt('password123'),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/dependents', [
                'id' => 'dep_infant_2',
                'name' => 'Infante 2 Años',
                'relationship' => 'Hijo/a',
                'birth_date' => now()->subYears(2)->toDateString(),
                'health_insurance' => 'Isapre Colmena',
            ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('age'));
        $this->assertGreaterThanOrEqual(23, $response->json('age_months'));
    }

    public function test_vaccine_age_alerts_command_sends_push_and_prevents_duplicates(): void
    {
        $user = User::create([
            'name' => 'Tutor Test',
            'email' => 'tutor@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        $dependent = Dependent::create([
            'id' => 'dep_baby_6m',
            'user_id' => $user->id,
            'name' => 'Bebé 6 Meses',
            'relationship' => 'Hijo/a',
            'age' => 0,
            'birth_date' => now()->subMonths(6)->toDateString(),
            'health_insurance' => 'Fonasa',
        ]);

        $fcmMock = Mockery::mock(FcmService::class);
        $fcmMock->shouldReceive('notifyUser')
            ->once()
            ->with(
                $user->id,
                'Recordatorio de Vacunación Aura',
                Mockery::pattern('/6 meses/'),
                Mockery::any()
            );

        $this->app->instance(FcmService::class, $fcmMock);

        // First run: should alert
        $this->artisan('vaccines:send-age-alerts')
            ->assertSuccessful();

        $dependent->refresh();
        $this->assertEquals(6, $dependent->last_vaccine_alert_milestone);
        $this->assertNotNull($dependent->last_vaccine_alert_sent_at);

        // Second run: should NOT alert again
        $this->artisan('vaccines:send-age-alerts')
            ->assertSuccessful();
    }
}
