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
            $rows[] = [
                $id,
                $professional?->name ?? '(prestador eliminado)',
                $balance['pending_count'],
                '$' . number_format($balance['gross'], 0, ',', '.'),
                '$' . number_format($balance['retained'], 0, ',', '.'),
                '$' . number_format($balance['pending_net'], 0, ',', '.'),
            ];
        }

        $this->table(
            ['Id', 'Prestador', 'Atenciones', 'Bruto', 'Retenido', 'A transferir'],
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
            $earnings = ProfessionalEarning::where('professional_id', $id)
                ->where('status', 'pending')
                ->pluck('id')
                ->all();

            if ($earnings === []) {
                $this->warn("  $id no tiene saldo pendiente; se omite.");
                continue;
            }

            $net = $settlement->pendingBalance($id)['pending_net'];
            $name = Professional::find($id)?->name ?? $id;

            // `confirm()` devuelve el valor por defecto cuando la consola no es
            // interactiva, así que esto no bloquea un cron.
            if (!$this->confirm('¿Confirmas transferencia de $'
                . number_format($net, 0, ',', '.') . " a $name?", true)) {
                $this->line("  $id omitido.");
                continue;
            }

            $closed = $settlement->markPaid($earnings, $reference);
            $this->info("  ✓ $name: $closed atención(es) cerradas con la referencia $reference.");
        }

        return self::SUCCESS;
    }
}
