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

            Professional::updateOrCreate(['id' => $data['id']], $data + ['active' => true]);

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
