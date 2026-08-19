<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalEarning;
use App\Models\ProfessionalPayout;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

/**
 * Registra lo que la plataforma le debe a cada prestador y gestiona liquidaciones.
 *
 * El paciente paga siempre a Aura —nunca en efectivo al profesional—, así que
 * el ingreso llega completo a la cuenta de la plataforma. Este servicio anota,
 * por cada atención cobrada, cuánto se retuvo y cuánto queda pendiente de
 * dispersar con trazabilidad completa (REQ-10).
 */
class SettlementService
{
    public const SOURCE_SERVICE_REQUEST = 'service_request';
    public const SOURCE_APPOINTMENT = 'appointment';

    /**
     * Comisión aplicable a un prestador, en puntos base.
     */
    public function commissionBpsFor(?Professional $professional): int
    {
        $own = $professional?->commission_bps;
        $bps = $own !== null ? (int) $own : (int) config('aura.commission_bps');

        return max(0, min(10000, $bps));
    }

    /**
     * Anota el devengo de una solicitud de servicio ya pagada.
     */
    public function recordForServiceRequest(ServiceRequest $request): ?ProfessionalEarning
    {
        if (empty($request->professional_id)) {
            return null;
        }

        if ($request->payment_status !== 'approved') {
            return null;
        }

        return $this->record(
            self::SOURCE_SERVICE_REQUEST,
            $request->id,
            $request->professional_id,
            (int) $request->final_price,
            $request->id,
            $request->updated_at ?? now(),
        );
    }

    /**
     * Anota el devengo de una cita médica pagada.
     */
    public function recordForAppointment(Appointment $appointment): ?ProfessionalEarning
    {
        if ($appointment->payment_status !== 'approved') {
            return null;
        }

        return $this->record(
            self::SOURCE_APPOINTMENT,
            $appointment->id,
            $appointment->professional_id,
            (int) $appointment->price,
            null,
            $appointment->scheduled_at ?? now(),
        );
    }

    /**
     * Saldo pendiente de dispersar de un prestador.
     *
     * @return array{pending_count:int, pending_net:int, retained:int, gross:int}
     */
    public function pendingBalance(string $professionalId): array
    {
        $rows = ProfessionalEarning::where('professional_id', $professionalId)
            ->where('status', 'pending')
            ->get();

        return [
            'pending_count' => $rows->count(),
            'pending_net' => (int) $rows->sum('net_amount'),
            'retained' => (int) $rows->sum('commission_amount'),
            'gross' => (int) $rows->sum('gross_amount'),
        ];
    }

    /**
     * Genera una liquidación consolidada (Payout) para un prestador (REQ-10).
     *
     * Idempotente y transaccional: bloquea las filas pendientes y guarda el snapshot bancario.
     */
    public function createPayout(
        string $professionalId,
        ?string $periodStart = null,
        ?string $periodEnd = null
    ): ?ProfessionalPayout {
        return DB::transaction(function () use ($professionalId, $periodStart, $periodEnd) {
            $professional = Professional::find($professionalId);
            if (!$professional) {
                return null;
            }

            // Buscar si ya existe una liquidación pendiente o no pagada para este periodo
            if ($periodStart !== null && $periodEnd !== null) {
                $existingPayout = ProfessionalPayout::where('professional_id', $professionalId)
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->whereIn('status', ['pending', 'paid'])
                    ->first();

                if ($existingPayout) {
                    return $existingPayout;
                }
            }

            // Obtener devengos pendientes no asignados a un payout activo
            $query = ProfessionalEarning::where('professional_id', $professionalId)
                ->where('status', 'pending')
                ->whereNull('payout_id')
                ->lockForUpdate();

            if ($periodStart !== null) {
                $query->whereDate('service_date', '>=', $periodStart);
            }
            if ($periodEnd !== null) {
                $query->whereDate('service_date', '<=', $periodEnd);
            }

            $pendingEarnings = $query->get();
            if ($pendingEarnings->isEmpty()) {
                return null;
            }

            $grossTotal = (int) $pendingEarnings->sum('gross_amount');
            $retainedTotal = (int) $pendingEarnings->sum('commission_amount');
            $netTotal = (int) $pendingEarnings->sum('net_amount');
            $count = $pendingEarnings->count();

            $bankSnapshot = [
                'rut' => $professional->rut,
                'bank_name' => $professional->bank_name,
                'account_type' => $professional->account_type,
                'account_number' => $professional->account_number,
                'billing_email' => $professional->billing_email,
            ];

            $payout = ProfessionalPayout::create([
                'professional_id' => $professionalId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_total' => $grossTotal,
                'retained_total' => $retainedTotal,
                'net_total' => $netTotal,
                'services_count' => $count,
                'status' => 'pending',
                'bank_snapshot' => $bankSnapshot,
            ]);

            ProfessionalEarning::whereIn('id', $pendingEarnings->pluck('id'))
                ->update([
                    'payout_id' => $payout->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);

            return $payout;
        });
    }

    /**
     * Cierra una liquidación consolidada tras ejecutar la transferencia bancaria (REQ-10).
     */
    public function markPayoutPaid(int $payoutId, string $payoutReference): bool
    {
        return DB::transaction(function () use ($payoutId, $payoutReference) {
            $payout = ProfessionalPayout::where('id', $payoutId)
                ->lockForUpdate()
                ->first();

            if (!$payout || $payout->status === 'paid') {
                return false;
            }

            $now = now();
            $payout->update([
                'status' => 'paid',
                'payout_reference' => $payoutReference,
                'paid_at' => $now,
            ]);

            ProfessionalEarning::where('payout_id', $payoutId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'paid',
                    'paid_at' => $now,
                    'payout_reference' => $payoutReference,
                ]);

            return true;
        });
    }

    /**
     * Cierra devengos como pagados tras ejecutar la transferencia.
     *
     * @param  list<int>  $earningIds
     */
    public function markPaid(array $earningIds, string $payoutReference): int
    {
        if ($earningIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($earningIds, $payoutReference) {
            return ProfessionalEarning::whereIn('id', $earningIds)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payout_reference' => $payoutReference,
                ]);
        });
    }

    private function record(
        string $sourceType,
        string $sourceId,
        string $professionalId,
        int $gross,
        ?string $bookingId = null,
        mixed $serviceDate = null
    ): ?ProfessionalEarning {
        if ($gross <= 0) {
            return null;
        }

        $professional = Professional::find($professionalId);
        $bps = $this->commissionBpsFor($professional);

        $commission = intdiv($gross * $bps, 10000);
        $net = $gross - $commission;

        return DB::transaction(function () use ($sourceType, $sourceId, $professionalId, $gross, $bps, $commission, $net, $bookingId, $serviceDate) {
            $existing = ProfessionalEarning::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ProfessionalEarning::create([
                'professional_id' => $professionalId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'booking_id' => $bookingId,
                'service_date' => $serviceDate,
                'gross_amount' => $gross,
                'commission_bps' => $bps,
                'commission_amount' => $commission,
                'net_amount' => $net,
                'status' => 'pending',
            ]);
        });
    }
}
