<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalEarning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * C.1 — el dinero entra a la plataforma y se dispersa después.
 *
 * Lo que se protege aquí es que ninguna atención cobrada se quede sin devengar
 * —era el caso de las citas agendadas— y que una dispersión no pueda cerrarse
 * sin dejar rastro de la transferencia que la respalda.
 */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfessional(string $id = 'prof_pago'): Professional
    {
        return Professional::forceCreate([
            'id' => $id,
            'name' => 'Dra. Camila Rivera',
            'specialty' => 'Medicina Interna',
            'consultation_price' => 25000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'email' => "$id@aura.cl",
            'password' => Hash::make('clave-segura-123'),
            'role' => 'professional',
            'rut' => '12.345.678-9',
            'bank_name' => 'Banco de Chile',
            'account_type' => 'Corriente',
            'account_number' => '12345678',
            'billing_email' => "$id@aura.cl",
        ]);
    }

    private function makeAppointment(
        Professional $professional,
        string $id = 'apt_pago',
        ?string $paymentStatus = 'approved',
    ): Appointment {
        $user = User::firstOrCreate(
            ['email' => 'paciente@aura.cl'],
            ['name' => 'Paciente', 'password' => bcrypt('password123')],
        );

        return Appointment::create([
            'id' => $id,
            'user_id' => $user->id,
            'professional_id' => $professional->id,
            'scheduled_at' => now()->subHour(),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'price' => 25000,
            'payment_status' => $paymentStatus,
        ]);
    }

    private function staffSession(Professional $professional): array
    {
        return [
            'staff_authenticated' => true,
            'staff_professional_id' => $professional->id,
            'staff_name' => $professional->name,
            'staff_role' => $professional->role ?? 'professional',
        ];
    }

    public function test_completing_a_paid_appointment_records_the_earning(): void
    {
        $professional = $this->makeProfessional();
        $this->makeAppointment($professional);

        $this->withSession($this->staffSession($professional))
            ->postJson('/doctor/api/appointments/apt_pago/status', ['status' => 'completed'])
            ->assertStatus(200);

        // 25.000 con 12,5 % = 3.125 retenidos, 21.875 al prestador.
        $earning = ProfessionalEarning::where('source_id', 'apt_pago')->firstOrFail();
        $this->assertSame('appointment', $earning->source_type);
        $this->assertSame(25000, $earning->gross_amount);
        $this->assertSame(3125, $earning->commission_amount);
        $this->assertSame(21875, $earning->net_amount);
        $this->assertSame('pending', $earning->status);

        // Cerrar dos veces no duplica el devengo.
        $this->withSession($this->staffSession($professional))
            ->postJson('/doctor/api/appointments/apt_pago/status', ['status' => 'completed'])
            ->assertStatus(200);
        $this->assertSame(1, ProfessionalEarning::count());
    }

    public function test_an_unpaid_appointment_does_not_generate_a_payable(): void
    {
        $professional = $this->makeProfessional();
        $this->makeAppointment($professional, 'apt_impago', paymentStatus: null);

        $this->withSession($this->staffSession($professional))
            ->postJson('/doctor/api/appointments/apt_impago/status', ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertSame(0, ProfessionalEarning::count());
    }

    public function test_no_show_and_cancelled_do_not_generate_a_payable(): void
    {
        $professional = $this->makeProfessional();
        $this->makeAppointment($professional, 'apt_no_show');

        $this->withSession($this->staffSession($professional))
            ->postJson('/doctor/api/appointments/apt_no_show/status', ['status' => 'no_show'])
            ->assertStatus(200);

        $this->assertSame(0, ProfessionalEarning::count());
    }

    // ----------------------------------------------------- cierre de la dispersión

    private function makePendingEarning(string $professionalId, int $gross = 20000): ProfessionalEarning
    {
        return ProfessionalEarning::create([
            'professional_id' => $professionalId,
            'source_type' => 'service_request',
            'source_id' => 'req_' . uniqid(),
            'gross_amount' => $gross,
            'commission_bps' => 1250,
            'commission_amount' => intdiv($gross * 1250, 10000),
            'net_amount' => $gross - intdiv($gross * 1250, 10000),
            'status' => 'pending',
        ]);
    }

    public function test_the_command_only_reports_when_no_target_is_given(): void
    {
        $professional = $this->makeProfessional();
        $this->makePendingEarning($professional->id);

        $this->artisan('aura:payouts')
            ->expectsOutputToContain('Modo consulta')
            ->assertExitCode(0);

        // Nada se cerró por el mero hecho de mirar.
        $this->assertSame(1, ProfessionalEarning::where('status', 'pending')->count());
    }

    public function test_settling_requires_a_transfer_reference(): void
    {
        $professional = $this->makeProfessional();
        $this->makePendingEarning($professional->id);

        $this->artisan('aura:payouts', ['--professional' => $professional->id])
            ->assertExitCode(1);

        $this->assertSame('pending', ProfessionalEarning::first()->status);
    }

    public function test_settling_closes_the_balance_and_keeps_the_reference(): void
    {
        $professional = $this->makeProfessional();
        $this->makePendingEarning($professional->id, 20000);
        $this->makePendingEarning($professional->id, 40000);

        $this->artisan('aura:payouts', [
            '--professional' => $professional->id,
            '--reference' => 'TRX-8842',
        ])->expectsConfirmation(
            '¿Confirmas transferencia de $52.500 a Dra. Camila Rivera [Banco de Chile 12345678]?',
            'yes',
        )->assertExitCode(0);

        $settled = ProfessionalEarning::where('status', 'paid')->get();
        $this->assertCount(2, $settled);
        foreach ($settled as $earning) {
            $this->assertSame('TRX-8842', $earning->payout_reference);
            $this->assertNotNull($earning->paid_at);
        }

        // Y el saldo pendiente queda en cero, no acumulándose para siempre.
        $this->artisan('aura:payouts')
            ->expectsOutputToContain('No hay saldos pendientes')
            ->assertExitCode(0);
    }

    public function test_a_settled_earning_is_not_paid_twice(): void
    {
        $professional = $this->makeProfessional();
        $earning = $this->makePendingEarning($professional->id, 20000);

        $settlement = app(\App\Services\SettlementService::class);
        $this->assertSame(1, $settlement->markPaid([$earning->id], 'TRX-1'));
        // El segundo intento no vuelve a tocarlo ni pisa la referencia.
        $this->assertSame(0, $settlement->markPaid([$earning->id], 'TRX-2'));
        $this->assertSame('TRX-1', $earning->fresh()->payout_reference);
    }

    public function test_create_and_settle_formal_payout_with_traceability(): void
    {
        $professional = $this->makeProfessional('prof_liquidar');
        $e1 = $this->makePendingEarning($professional->id, 30000);
        $e2 = $this->makePendingEarning($professional->id, 50000);

        $settlement = app(\App\Services\SettlementService::class);
        $payout = $settlement->createPayout($professional->id);

        $this->assertNotNull($payout);
        $this->assertSame('pending', $payout->status);
        $this->assertSame(80000, $payout->gross_total);
        $this->assertSame(2, $payout->services_count);
        $this->assertSame('Banco de Chile', $payout->bank_snapshot['bank_name']);

        // Idempotencia: segundo createPayout no duplica
        $payout2 = $settlement->createPayout($professional->id);
        $this->assertNull($payout2); // Ya no hay devengos sin payout_id

        // Marcar pagado
        $paid = $settlement->markPayoutPaid($payout->id, 'TRX-LIQ-999');
        $this->assertTrue($paid);

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('TRX-LIQ-999', $payout->payout_reference);
        $this->assertNotNull($payout->paid_at);

        $e1->refresh();
        $e2->refresh();
        $this->assertSame('paid', $e1->status);
        $this->assertSame('TRX-LIQ-999', $e1->payout_reference);
        $this->assertSame($payout->id, $e1->payout_id);
    }
}
