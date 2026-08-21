<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'id' => 'plan_individual',
                'name' => 'Plan Aura Esencial',
                'description' => 'Ideal para el cuidado individual y seguimiento médico continuo.',
                'monthly_price' => 14990,
                'included_consultations' => 1,
                'discount_percentage' => 15,
                'features' => [
                    '1 consulta médica o de enfermería a domicilio al mes',
                    '15% de descuento en exámenes de laboratorio',
                    'Orientación médica digital y seguimiento 24/7',
                    'Sin costo de despacho en medicamentos',
                ],
                'active' => true,
            ],
            [
                'id' => 'plan_familiar',
                'name' => 'Plan Aura Familiar',
                'description' => 'Cobertura integral para el titular y hasta 4 familiares o dependientes.',
                'monthly_price' => 29990,
                'included_consultations' => 3,
                'discount_percentage' => 25,
                'features' => [
                    '3 consultas a domicilio mensuales para todo el grupo familiar',
                    '25% de descuento en tomas de muestra y exámenes de laboratorio',
                    'Ficha clínica digital unificada para dependientes',
                    'Atención prioritaria y despacho preferencial de ambulancias',
                ],
                'active' => true,
            ],
            [
                'id' => 'plan_senior',
                'name' => 'Plan Aura Senior Care',
                'description' => 'Especialmente diseñado para adultos mayores y personas con dependencia.',
                'monthly_price' => 39990,
                'included_consultations' => 4,
                'discount_percentage' => 30,
                'features' => [
                    '4 atenciones a domicilio mensuales (médico, enfermería o kinesiología)',
                    '30% de descuento en laboratorio a domicilio',
                    'Monitoreo preventivo y recordatorio de vacunas',
                    'Contacto directo con médico de cabecera',
                ],
                'active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['id' => $plan['id']],
                $plan,
            );
        }
    }
}
