<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClinicalService;

class ClinicalServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            [
                'id' => 'enfermeria',
                'title' => 'Procedimientos de Enfermería',
                'short_title' => 'Enfermería',
                'subtitle' => 'Administración de medicamentos, curaciones, sondas e inyecciones.',
                'description' => 'Personal de enfermería certificado asiste a su domicilio para administración de medicamentos endovenosos, intramusculares, curaciones simples y complejas, manejo de vías habituales.',
                'base_price' => 15000,
                'base_eta' => '30 - 50',
                'requires_prescription' => true,
                'icon_name' => 'Activity',
                'warning_info' => 'Toda atención de enfermería a domicilio requiere contar con una orden médica legible o receta firmada por un profesional autorizado.',
                'placeholder_text' => 'Ej. Inyección intramuscular de amoxicilina, curación de herida operatoria...',
            ],
            [
                'id' => 'medico',
                'title' => 'Atención Médica a Domicilio',
                'short_title' => 'Médico',
                'subtitle' => 'Evaluación y tratamiento médico general para cuadros agudos.',
                'description' => 'Atención de médico generalista para control de síntomas respiratorios, gastrointestinales, cuadro infeccioso bacteriano leve o descompensación de patología crónica estándar.',
                'base_price' => 40000,
                'base_eta' => '45 - 60',
                'requires_prescription' => false,
                'icon_name' => 'UserRoundPlus',
                'warning_info' => 'Servicio exclusivo para atención no urgente y semi-urgente. En caso de emergencia de riesgo vital (dolor opresivo de pecho de inicio súbito, asfixia severa, pérdida de conciencia), llame inmediatamente al servicio de emergencias públicas.',
                'placeholder_text' => 'Ej. Fiebre alta persistente, tos seca dolorosa, malestar estomacal agudo de 24 horas...',
            ],
            [
                'id' => 'kine_motora',
                'title' => 'Kinesiología Motora',
                'short_title' => 'Kine Motora',
                'subtitle' => 'Rehabilitación física, masoterapia clínica y fisioterapia.',
                'description' => 'Ejercicios terapéuticos para rehabilitación traumatológica (fracturas, reincidencia de esguinces), post-operatoria, reeducación de la marcha o apoyo funcional a adultos mayores.',
                'base_price' => 22000,
                'base_eta' => '60 - 90',
                'requires_prescription' => true,
                'icon_name' => 'Footprints',
                'warning_info' => 'Toda sesión de kinesiología requiere de un pedido u orden médica que detalle el número de sesiones indicadas y el diagnóstico.',
                'placeholder_text' => 'Ej. Rehabilitación de fractura de cadera, tratamiento post-esguince de tobillo derecho...',
            ],
            [
                'id' => 'kine_respiratoria',
                'title' => 'Kinesiología Respiratoria',
                'short_title' => 'Kine Respiratoria',
                'subtitle' => 'Kinesioterapia bronquial para niños, adultos y adultos mayores.',
                'description' => 'Aseo bronquial, aspiración de secreciones, oxigenoterapia domiciliaria, ejercicios para favorecer la ventilación pulmonar tras neumonías o exacerbaciones de asma/EPOC.',
                'base_price' => 24000,
                'base_eta' => '45 - 75',
                'requires_prescription' => true,
                'icon_name' => 'Lungs',
                'warning_info' => 'Toda sesión de kinesiología respiratoria requiere contar con indicación u orden médica que justifique y dirija el tratamiento clínico.',
                'placeholder_text' => 'Ej. Cuadro de bronquitis aguda obstructiva, manejo de secreciones post-neumonía...',
            ],
            [
                'id' => 'cuidados',
                'title' => 'Servicio de Cuidados Domiciliarios',
                'short_title' => 'Cuidados',
                'subtitle' => 'Asistentes de cuidado para adultos mayores o dependientes.',
                'description' => 'Acompañamiento especializado, asistencia en actividades de la vida diaria, administración y control estricto de medicamentos orales, higiene, confort clínico y movilización.',
                'base_price' => 12000,
                'base_eta' => '120 - 180',
                'requires_prescription' => false,
                'icon_name' => 'HeartHandshake',
                'warning_info' => 'Este servicio es de apoyo de cuidados y enfermería menor. No es una internación domiciliaria intensiva. La contratación mínima es de 3 horas.',
                'placeholder_text' => 'Ej. Cuidado y asistencia para adulto mayor dependiente durante la tarde, apoyo en aseo...',
            ],
            [
                'id' => 'ambulancia',
                'title' => 'Ambulancia de Transporte Programado',
                'short_title' => 'Ambulancia',
                'subtitle' => 'Traslado clínico básico o medicalizado no urgente.',
                'description' => 'Traslado programado en ambulancia para pacientes con movilidad reducida, altas de internaciones, realización de exámenes de especialidad fuera del hogar o consultas externas.',
                'base_price' => 18500,
                'base_eta' => '15 - 30',
                'requires_prescription' => false,
                'icon_name' => 'Truck',
                'warning_info' => 'Servicio de ambulancia exclusivamente para traslados no urgentes o programados de antemano. Verifique disponibilidad geográfica antes de solicitar.',
                'placeholder_text' => 'Ej. Retorno programado desde centro asistencial tras alta médica en camilla...',
            ],
            [
                'id' => 'radiologia',
                'title' => 'Radiología a Domicilio',
                'short_title' => 'Radiología',
                'subtitle' => 'Estudios de rayos X con equipo digital portátil en casa.',
                'description' => 'Estudios óseos o pulmonares rápidos a domicilio con equipo rodable de baja dosis. Ideal para pacientes postrados o de movilidad reducida. Entrega inmediata de informe digitalizado.',
                'base_price' => 35000,
                'base_eta' => '90 - 120',
                'requires_prescription' => true,
                'icon_name' => 'ScanFace',
                'warning_info' => 'Es estrictamente obligatorio adjuntar el pedido médico con la solicitud que justifique la exposición radiológica regulada.',
                'placeholder_text' => 'Ej. Radiografía de tórax frontal, radiografía de cadera derecha dos proyecciones...',
            ],
            [
                'id' => 'laboratorio',
                'title' => 'Toma de Muestras y Laboratorio',
                'short_title' => 'Laboratorio',
                'subtitle' => 'Extracción de sangre, orina y tomas microbiológicas.',
                'description' => 'Toma de muestras por personal capacitado en condiciones de bioseguridad. Reporte de resultados integrado directo a su correo electrónico y portal personal en tiempo récord.',
                'base_price' => 19500,
                'base_eta' => '60 - 90',
                'requires_prescription' => true,
                'icon_name' => 'FlaskConical',
                'warning_info' => 'Toma de muestras requiere receta médica u orden de exámenes detallada para el debido procesamiento pre-analítico en laboratorio.',
                'placeholder_text' => 'Ej. Hemograma completo, perfil lipídico, glucemia en ayunas, urocultivo...',
            ],
            [
                'id' => 'electrocardiograma',
                'title' => 'Electrocardiogramas (ECG)',
                'short_title' => 'Electrocardiograma',
                'subtitle' => 'Trazados ECG de 12 derivaciones con informe de cardiólogo.',
                'description' => 'Registro de la actividad eléctrica cardíaca en domicilio con electrocardiógrafo digital de alta resolución. Incluye pre-visualización e informe interpretado por médico especialista.',
                'base_price' => 21000,
                'base_eta' => '45 - 60',
                'requires_prescription' => true,
                'icon_name' => 'Heart',
                'warning_info' => 'Requiere pedido médico clínico que especifique la necesidad del estudio. No apto para dolor torácico en evolución (solicite urgencias).',
                'placeholder_text' => 'Ej. ECG de reposo programado para aptitud quirúrgica cardíaca...',
            ],
        ];

        foreach ($services as $service) {
            ClinicalService::updateOrCreate(['id' => $service['id']], $service);
        }
    }
}
