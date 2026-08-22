<?php

namespace Database\Seeders;

use App\Models\Dependent;
use App\Models\Professional;
use App\Models\SavedAddress;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One ready-to-use QA account per role.
 *
 * All of them share the password `aura1234` so testers don't have to juggle
 * credentials. Run with:
 *   php artisan db:seed --class=Database\\Seeders\\TestUsersSeeder
 */
class TestUsersSeeder extends Seeder
{
    public const PASSWORD = 'aura1234';

    /** email => [id, name, role] */
    public const ACCOUNTS = [
        'paciente@aura.cl' => [10, 'Paciente de Prueba', 'patient'],
        'tutor@aura.cl' => [11, 'Tutor de Prueba', 'dependent_tutor'],
        'profesional@aura.cl' => [12, 'Profesional de Prueba', 'doctor_provider'],
        'operador@aura.cl' => [13, 'Operador de Prueba', 'operator_admin'],
        'conductor@aura.cl' => [14, 'Conductor de Prueba', 'ambulance_driver'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $email => [$id, $name, $role]) {
            $user = User::updateOrCreate(['id' => $id], [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(self::PASSWORD),
            ]);

            // role is guarded against mass assignment, so it is set explicitly.
            // The professional account is also linked to its clinical record so
            // it can work from the app, not only from the web portal.
            $user->forceFill([
                'role' => $role,
                'is_test_account' => true,
                'professional_id' => $role === 'doctor_provider'
                    ? 'prof_test_profesional'
                    : null,
            ])->save();

            // Every test account gets an address in a covered zone so the
            // zone-based ETA has something real to work with.
            SavedAddress::updateOrCreate(['id' => "addr_test_$id"], [
                'user_id' => $user->id,
                'label' => 'Casa (cuenta de prueba)',
                'text' => 'Av. Providencia 1234, Providencia, Santiago',
            ]);
        }

        // The tutor account needs someone to be a tutor of.
        Dependent::updateOrCreate(['id' => 'dep_test_tutor'], [
            'user_id' => 11,
            'name' => 'Lucía Fernández (menor)',
            'relationship' => 'Hija',
            'age' => 6,
            'health_insurance' => 'Fonasa Tramo B',
            'medical_conditions' => 'Sin antecedentes relevantes.',
        ]);

        // Portal (web) counterparts: the professional and the operator also
        // need to be able to log into /doctor.
        $prof1 = Professional::find('prof_test_profesional') ?? new Professional(['id' => 'prof_test_profesional']);
        $prof1->forceFill([
            'name' => 'Dr. Profesional de Prueba',
            'specialty' => 'Medicina General',
            'bio' => 'Cuenta de prueba para el portal de profesionales.',
            'consultation_price' => 20000,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'coverage_zones' => 'Providencia, Ñuñoa, Santiago',
            'duty_status' => 'disponible',
            'email' => 'profesional@aura.cl',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'professional',
        ])->save();

        // Laboratorista de prueba (Módulo E). Es una cuenta aparte porque la
        // toma de muestras se agenda contra bloques publicados por alguien
        // habilitado con `provides_lab`, no contra la guardia médica.
        $prof2 = Professional::find('prof_test_laboratorio') ?? new Professional(['id' => 'prof_test_laboratorio']);
        $prof2->forceFill([
            'name' => 'TM. Laboratorio de Prueba',
            'specialty' => 'Tecnología Médica',
            'bio' => 'Cuenta de prueba para el área de laboratorio del portal.',
            'consultation_price' => 19500,
            'consultation_duration_minutes' => 30,
            'active' => true,
            'provides_lab' => true,
            'coverage_zones' => 'Providencia, Ñuñoa, Santiago',
            'duty_status' => 'disponible',
            'email' => 'laboratorio@aura.cl',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'professional',
        ])->save();

        // Su contraparte en la app, para que pueda trabajar desde el teléfono.
        $labUser = User::updateOrCreate(['id' => 15], [
            'name' => 'Laboratorista de Prueba',
            'email' => 'laboratorista@aura.cl',
            'password' => Hash::make(self::PASSWORD),
        ]);
        $labUser->forceFill([
            'role' => 'doctor_provider',
            'is_test_account' => true,
            'professional_id' => 'prof_test_laboratorio',
        ])->save();

        $prof3 = Professional::find('staff_test_operador') ?? new Professional(['id' => 'staff_test_operador']);
        $prof3->forceFill([
            'name' => 'Operador de Prueba',
            'specialty' => 'Administración',
            'consultation_price' => 0,
            'consultation_duration_minutes' => 30,
            'active' => false,
            'duty_status' => 'desconectado',
            'email' => 'operador@aura.cl',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'admin',
        ])->save();

        $this->command?->info('Cuentas de prueba creadas (contraseña: ' . self::PASSWORD . ')');
        foreach (self::ACCOUNTS as $email => [, , $role]) {
            $this->command?->line("  - $email  ($role)");
        }
        $this->command?->line('  - laboratorista@aura.cl  (doctor_provider · laboratorio)');
        $this->command?->line('  - laboratorio@aura.cl  (portal web · laboratorio)');
    }
}
