<?php

namespace App\Console\Commands;

use App\Models\Professional;
use App\Models\ProfessionalEarning;
use App\Services\SettlementService;
use Illuminate\Console\Command;

/**
 * Cierre de la dispersión quincenal a prestadores.
 *
 * El dinero entra completo a la plataforma y se transfiere después. Hasta ahora
 * `professional_earnings` solo acumulaba: `SettlementService::markPaid()`
 * existía pero no lo llamaba nadie, así que el saldo crecía sin que hubiera
 * forma de saldarlo ni de saber qué se había pagado ya.
 *
 * La transferencia bancaria se hace fuera de aquí. Este comando es lo que la
 * deja registrada, y por eso exige la referencia del pago: sin ella no hay
 * manera de conciliar una fila de esta tabla con un movimiento real.
 *
 *   php artisan aura:payouts                       # solo listar
 *   php artisan aura:payouts --professional=prof_x --reference=TRX-8842
 */
class SettleProfessionalPayouts extends Command
{
    protected $signature = 'aura:payouts
        {--professional= : Id del prestador a saldar}
        {--reference= : Referencia de la transferencia ya realizada}
        {--all : Saldar a todos los prestadores con saldo pendiente}';

    protected $description = 'Lista y cierra las dispersiones pendientes a prestadores';

    public function handle(SettlementService $settlement): int
    {
        $reference = (string) $this->option('reference');
        $professionalId = $this->option('professional');
        $all = (bool) $this->option('all');

        $pendingIds = ProfessionalEarning::where('status', 'pending')
            ->distinct()
            ->pluck('professional_id');

        if ($pendingIds->isEmpty()) {
            $this->info('No hay saldos pendientes de dispersar.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Saldos pendientes');
        $this->line(str_repeat('─', 72));

        $rows = [];
        foreach ($pendingIds as $id) {
            $balance = $settlement->pendingBalance($id);
            $professional = Professional::find($id);
            $bankInfo = $professional
                ? ($professional->bank_name ? "{$professional->bank_name} · {$professional->account_type} {$professional->account_number}" : 'Sin datos')
                : '-';

            $rows[] = [
                $id,
                $professional?->name ?? '(prestador eliminado)',
                $bankInfo,
                $balance['pending_count'],
                '$' . number_format($balance['gross'], 0, ',', '.'),
                '$' . number_format($balance['retained'], 0, ',', '.'),
                '$' . number_format($balance['pending_net'], 0, ',', '.'),
            ];
        }

        $this->table(
            ['Id', 'Prestador', 'Cuenta Bancaria', 'Atenciones', 'Bruto', 'Retenido', 'A transferir'],
            $rows,
        );

        // Sin --professional ni --all esto es solo un informe. Cerrar una
        // dispersión es irreversible desde el comando, así que no debe ser lo
        // que ocurre cuando alguien lo ejecuta para mirar.
        if (!$professionalId && !$all) {
            $this->line('');
            $this->comment('Modo consulta. Para cerrar una dispersión:');
            $this->line('  php artisan aura:payouts --professional=<id> --reference=<n.º de transferencia>');

            return self::SUCCESS;
        }

        if ($reference === '') {
            $this->error('Falta --reference: es lo que permite conciliar este cierre con la transferencia real.');

            return self::FAILURE;
        }

        $targets = $all ? $pendingIds->all() : [$professionalId];

        foreach ($targets as $id) {
            $professional = Professional::find($id);
            if (!$professional) {
                $this->warn("  $id no existe; se omite.");
                continue;
            }

            $balance = $settlement->pendingBalance($id);
            if ($balance['pending_count'] === 0) {
                $this->warn("  $id no tiene saldo pendiente; se omite.");
                continue;
            }

            $net = $balance['pending_net'];
            $name = $professional->name;
            $bankDesc = $professional->bank_name ? " [{$professional->bank_name} {$professional->account_number}]" : ' [Sin datos bancarios]';

            if (!$this->confirm("¿Confirmas transferencia de $" . number_format($net, 0, ',', '.') . " a $name$bankDesc?", true)) {
                $this->line("  $id omitido.");
                continue;
            }

            // Generar payout formal y marcarlo como pagado
            $payout = $settlement->createPayout($id, now()->startOfWeek()->subWeek()->toDateString(), now()->endOfWeek()->subWeek()->toDateString());
            if (!$payout) {
                // Fallback a payout de todo lo pendiente
                $payout = $settlement->createPayout($id);
            }

            if ($payout) {
                $settlement->markPayoutPaid($payout->id, $reference);
                $this->info("  ✓ $name: Liquidación #{$payout->id} ({$payout->services_count} atenciones) cerrada con la referencia $reference.");
            } else {
                $this->warn("  No se pudo generar la liquidación para $name.");
            }
        }

        return self::SUCCESS;
    }
}
