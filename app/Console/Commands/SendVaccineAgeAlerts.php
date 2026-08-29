<?php

namespace App\Console\Commands;

use App\Models\Dependent;
use App\Services\FcmService;
use Illuminate\Console\Command;

/**
 * REQ-14: Alertas proactivas del calendario de vacunación según fecha de nacimiento.
 */
class SendVaccineAgeAlerts extends Command
{
    protected $signature = 'vaccines:send-age-alerts';
    protected $description = 'Send push reminders for national immunization milestones based on dependents birth dates';

    /**
     * Hitos clave del Programa Nacional de Inmunizaciones (meses y vacunas).
     */
    private const MILESTONES = [
        2 => 'Hexavalente (1ª dosis), Neumocócica conjugada (1ª dosis)',
        4 => 'Hexavalente (2ª dosis), Neumocócica conjugada (2ª dosis)',
        6 => 'Hexavalente (3ª dosis)',
        12 => 'Tres Vírica (1ª dosis), Meningocócica (1ª dosis), Neumocócica (refuerzo)',
        18 => 'Hexavalente (1er refuerzo), Hepatitis A, Fiebre Amarilla (Isla de Pascua)',
        36 => 'Tres Vírica (2ª dosis / 3 años)',
        60 => 'DTP acelular (1er básico / 5 años)',
        108 => 'VPH (1ª dosis / 4º básico / 9 años)',
        156 => 'dTpa (8º básico / 13 años)',
    ];

    public function handle(FcmService $fcm): int
    {
        $sent = 0;
        $dependents = Dependent::whereNotNull('birth_date')->get();

        foreach ($dependents as $dependent) {
            $months = (int) round(\Carbon\Carbon::parse($dependent->birth_date)->floatDiffInMonths(now()));

            // Encontrar el hito más cercano dentro de la ventana (+- 1 mes)
            $closestMilestone = null;
            $minDiff = PHP_INT_MAX;
            foreach (self::MILESTONES as $milestoneMonths => $vaccines) {
                $diff = abs($months - $milestoneMonths);
                if ($diff <= 1 && $diff < $minDiff && $dependent->last_vaccine_alert_milestone !== $milestoneMonths) {
                    $minDiff = $diff;
                    $closestMilestone = $milestoneMonths;
                }
            }

            if ($closestMilestone !== null) {
                $vaccines = self::MILESTONES[$closestMilestone];
                $fcm->notifyUser(
                    $dependent->user_id,
                    'Recordatorio de Vacunación Aura',
                    "A {$dependent->name} ({$months} meses) le corresponde la vacunación de los {$closestMilestone} meses: {$vaccines}.",
                    [
                        'type' => 'vaccine_reminder',
                        'dependent_id' => $dependent->id,
                        'milestone_months' => (string) $closestMilestone,
                    ]
                );

                $dependent->update([
                    'last_vaccine_alert_milestone' => $closestMilestone,
                    'last_vaccine_alert_sent_at' => now(),
                ]);

                $sent++;
            }
        }

        $this->info("Vaccine milestone reminders sent: $sent");

        return self::SUCCESS;
    }
}
