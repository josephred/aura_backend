<?php

namespace Database\Seeders;

use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use Illuminate\Database\Seeder;

class ProfessionalSeeder extends Seeder
{
    public function run(): void
    {
        $professionals = [
            [
                'id' => 'prof_camila_rivera',
                'registration_number' => 'SIS-118472',
                'years_of_experience' => 12,
                'coverage_zones' => 'Providencia, Ñuñoa, Las Condes, Santiago',
                'duty_status' => 'disponible',
                'name' => 'Dra. Camila Rivera N.',
                'specialty' => 'Medicina Interna',
                'bio' => 'Médico internista con 12 años de experiencia en atención de adultos y adultos mayores.',
                'consultation_price' => 25000,
                'consultation_duration_minutes' => 30,
                // Lun a vie, mañana y tarde
                'schedule' => [
                    [1, '09:00', '13:00'], [1, '15:00', '18:00'],
                    [2, '09:00', '13:00'], [2, '15:00', '18:00'],
                    [3, '09:00', '13:00'],
                    [4, '09:00', '13:00'], [4, '15:00', '18:00'],
                    [5, '09:00', '13:00'],
                ],
            ],
            [
                'id' => 'prof_sebastian_leyton',
                'registration_number' => 'SIS-204915',
                'years_of_experience' => 8,
                'coverage_zones' => 'Providencia, Santiago, Recoleta, Independencia',
                'duty_status' => 'disponible',
                'name' => 'Dr. Sebastián Leyton',
                'specialty' => 'Medicina General',
                'bio' => 'Médico general orientado a medicina familiar, controles crónicos y consultas agudas.',
                'consultation_price' => 20000,
                'consultation_duration_minutes' => 30,
                'schedule' => [
                    [1, '10:00', '14:00'],
                    [2, '10:00', '14:00'], [2, '16:00', '19:00'],
                    [3, '10:00', '14:00'], [3, '16:00', '19:00'],
                    [4, '10:00', '14:00'],
                    [5, '10:00', '14:00'], [5, '16:00', '19:00'],
                    [6, '10:00', '13:00'],
                ],
            ],
            [
                'id' => 'prof_maria_diaz',
                'registration_number' => 'SIS-331208',
                'years_of_experience' => 9,
                'coverage_zones' => 'Vitacura, Las Condes, Lo Barnechea, Providencia',
                'duty_status' => 'disponible',
                'name' => 'Klga. María José Díaz',
                'specialty' => 'Kinesiología',
                'bio' => 'Kinesióloga especialista en rehabilitación motora y respiratoria.',
                'consultation_price' => 18000,
                'consultation_duration_minutes' => 45,
                'schedule' => [
                    [1, '09:00', '13:00'],
                    [2, '14:00', '19:00'],
                    [3, '09:00', '13:00'],
                    [4, '14:00', '19:00'],
                    [5, '09:00', '13:00'],
                ],
            ],
            [
                'id' => 'prof_patricia_jara',
                'registration_number' => 'SIS-097654',
                'years_of_experience' => 14,
                'coverage_zones' => 'Providencia, Ñuñoa, Macul, La Florida',
                'duty_status' => 'disponible',
                'name' => 'Enf. Patricia Jara',
                'specialty' => 'Enfermería',
                'bio' => 'Enfermera clínica: controles, curaciones y educación de pacientes.',
                'consultation_price' => 15000,
                'consultation_duration_minutes' => 30,
                'schedule' => [
                    [1, '08:00', '12:00'],
                    [2, '08:00', '12:00'],
                    [3, '08:00', '12:00'], [3, '14:00', '17:00'],
                    [4, '08:00', '12:00'],
                    [5, '08:00', '12:00'], [5, '14:00', '17:00'],
                ],
            ],
        ];

        foreach ($professionals as $data) {
            $schedule = $data['schedule'];
            unset($data['schedule']);

            $prof = Professional::find($data['id']) ?? new Professional(['id' => $data['id']]);
            $prof->forceFill($data + ['active' => true])->save();

            ProfessionalSchedule::where('professional_id', $data['id'])->delete();
            foreach ($schedule as [$day, $start, $end]) {
                ProfessionalSchedule::create([
                    'professional_id' => $data['id'],
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                ]);
            }
        }
    }
}
