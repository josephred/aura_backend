<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\LabSchedule;
use App\Models\Professional;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ClinicalServicesSeeder;
use Database\Seeders\ProfessionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PostgresRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicalServicesSeeder::class);
    }

    /**
     * Regresión P1.1 (a): /api/bookings/active con solicitudes agendadas.
     * En Postgres, 'is_scheduled = 1' falla con 'operator does not exist: boolean = integer'.
     * Debe ordenarse correctamente usando booleanos nativos de Laravel.
     */
    public function test_active_bookings_handles_scheduled_and_immediate_requests(): void
    {
        $user = User::factory()->create();

        // 1. Solicitud inmediata activa
        ServiceRequest::create([
            'id' => 'req_immediate_test',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'en_camino',
            'current_step' => 2,
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 100',
            'final_price' => 25000,
            'eta_minutes' => 30,
            'start_time' => '10:30',
            'payment_method' => 'mercadopago',
            'is_scheduled' => false,
        ]);

        // 2. Solicitud agendada para hoy
        ServiceRequest::create([
            'id' => 'req_scheduled_test',
            'user_id' => $user->id,
            'service_id' => 'laboratorio',
            'status' => 'scheduled',
            'current_step' => 1,
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 100',
            'final_price' => 19500,
            'eta_minutes' => 45,
            'start_time' => '08:00',
            'payment_method' => 'mercadopago',
            'is_scheduled' => true,
            'scheduled_at' => now()->addHours(2),
        ]);

        $response = $this->actingAs($user)->getJson('/api/bookings/active');

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);
        // Debe priorizar la atención inmediata
        $this->assertSame('req_immediate_test', $data['id']);
    }

    /**
     * Regresión P1.1 (b): Reserva de último cupo de laboratorio.
     * En Postgres, 'lockForUpdate()->count()' revienta con error de agregación.
     */
    public function test_last_lab_slot_booking_capacity_and_exhaustion(): void
    {
        $prof = Professional::forceCreate([
            'id' => 'prof_lab_reg',
            'name' => 'TM. Laboratorio Regresión',
            'specialty' => 'Tecnología Médica',
            'consultation_price' => 19500,
            'active' => true,
            'email' => 'lab_reg@aura.cl',
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
        ]);

        \App\Models\ClinicalService::firstOrCreate(
            ['id' => 'laboratorio'],
            [
                'title' => 'Toma de Muestras',
                'short_title' => 'Laboratorio',
                'subtitle' => 'Exámenes a domicilio',
                'description' => 'Servicio de laboratorio',
                'base_price' => 19500,
                'base_eta' => '24 - 48 hrs',
                'requires_prescription' => true,
                'icon_name' => 'biotech',
            ]
        );
        $prof->services()->sync(['laboratorio']);

        $targetDate = now()->addDays(3);
        $schedule = LabSchedule::create([
            'professional_id' => $prof->id,
            'date' => $targetDate->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'slot_minutes' => 30,
            'capacity' => 1,
            'active' => true,
        ]);

        $user1 = User::create([
            'name' => 'Paciente 1',
            'email' => 'paciente1@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        $user2 = User::create([
            'name' => 'Paciente 2',
            'email' => 'paciente2@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        $slotTime = $targetDate->copy()->setTime(8, 0)->format('Y-m-d H:i:s');

        // Primer paciente reserva el único cupo disponible
        $response1 = $this->actingAs($user1)->postJson('/api/lab/requests', [
            'schedule_id' => $schedule->id,
            'starts_at' => $slotTime,
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234',
            'exam_required' => 'Hemograma',
        ]);

        $response1->assertCreated();

        // Segundo paciente intenta reservar el mismo cupo ya agotado
        $response2 = $this->actingAs($user2)->postJson('/api/lab/requests', [
            'schedule_id' => $schedule->id,
            'starts_at' => $slotTime,
            'patient_type' => 'self',
            'address_text' => 'Calle Los Leones 567',
            'exam_required' => 'Hemograma',
        ]);

        $response2->assertStatus(409)
            ->assertJsonPath('error', 'Ese horario ya no está disponible. Elige otro bloque.');
    }
}
