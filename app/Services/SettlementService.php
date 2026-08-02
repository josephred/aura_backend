<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\ProfessionalEarning;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

/**
 * Registra lo que la plataforma le debe a cada prestador.
 *
 * El paciente paga siempre a Aura —nunca en efectivo al profesional—, así que
 * el ingreso llega completo a la cuenta de la plataforma. Este servicio anota,
 * por cada atención cobrada, cuánto se retuvo y cuánto queda pendiente de
 * dispersar. La transferencia bancaria en sí es un proceso administrativo
 * aparte: aquí queda el saldo que esa transferencia debe pagar, y `markPaid()`
 * lo cierra cuando se ejecuta.
 */
class SettlementService
{
    public const SOURCE_SERVICE_REQUEST = 'service_request';
    public const SOURCE_APPOINTMENT = 'appointment';

    /**
     * Comisión aplicable a un prestador, en puntos base.
     *
     * La tasa propia del prestador manda sobre la de la plataforma: es el
     * gancho por el que una calificación excelente puede bajarle la retención
     * (B.4) sin tocar este código.
     */
    public function commissionBpsFor(?Professional $professional): int
    {
        $own = $professional?->commission_bps;
        $bps = $own !== null ? (int) $own : (int) config('aura.commission_bps');

        // Una comisión fuera de rango dejaría un neto negativo o un devengo
        // absurdo; se acota antes de escribir nada.
        return max(0, min(10000, $bps));
    }

    /**
     * Anota el devengo de una solicitud de servicio ya pagada.
     *
     * Idempotente: el webhook de Mercado Pago puede llegar repetido y el
     * profesional puede cerrar la atención dos veces desde el portal.
     */
    public function recordForServiceRequest(ServiceRequest $request): ?ProfessionalEarning
    {
        if (empty($request->professional_id)) {
            return null;
        }

        // Sin cobro confirmado no hay nada que dispersar. En desarrollo, donde
        // la pasarela no está configurada, `payment_status` queda nulo y la
        // atención igual se completa: en ese caso tampoco se devenga.
        if ($request->payment_status !== 'approved') {
            return null;
        }

        return $this->record(
            self::SOURCE_SERVICE_REQUEST,
            $request->id,
            $request->professional_id,
            (int) $request->final_price,
        );
    }

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
     * Cierra devengos como pagados tras ejecutar la transferencia.
     *
     * @param  list<int>  $earningIds
     */
    public function markPaid(array $earningIds, string $payoutReference): int
    {
        if ($earningIds === []) {
            return 0;
        }

        return ProfessionalEarning::whereIn('id', $earningIds)
            ->where('status', 'pending')
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payout_reference' => $payoutReference,
            ]);
    }

    private function record(
        string $sourceType,
        string $sourceId,
        string $professionalId,
        int $gross,
    ): ?ProfessionalEarning {
        if ($gross <= 0) {
            return null;
        }

        $professional = Professional::find($professionalId);
        $bps = $this->commissionBpsFor($professional);

        // Todo en enteros: la retención se redondea hacia abajo y la diferencia
        // queda a favor del prestador, nunca al revés.
        $commission = intdiv($gross * $bps, 10000);
        $net = $gross - $commission;

        return DB::transaction(function () use ($sourceType, $sourceId, $professionalId, $gross, $bps, $commission, $net) {
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
                'gross_amount' => $gross,
                'commission_bps' => $bps,
                'commission_amount' => $commission,
                'net_amount' => $net,
                'status' => 'pending',
            ]);
        });
    }
}
