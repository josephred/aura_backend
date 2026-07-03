<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create default user with ID = 1
        User::updateOrCreate([
            'id' => 1
        ], [
            'name' => 'Usuario Principal',
            'email' => 'principal@aura.cl',
            'password' => bcrypt('password'),
        ]);

        // 2. Load Clinical Services
        $this->call(ClinicalServicesSeeder::class);

        // 3. Load Dependents
        \App\Models\Dependent::updateOrCreate([
            'id' => 'dep_1'
        ], [
            'user_id' => 1,
            'name' => 'Margarita Sotomayor Arancibia',
            'relationship' => 'Madre',
            'age' => 76,
            'health_insurance' => 'Isapre Banmédica',
            'medical_conditions' => 'Hipertensión arterial controlada, artrosis leve de cadera izquierda.',
        ]);

        \App\Models\Dependent::updateOrCreate([
            'id' => 'dep_2'
        ], [
            'user_id' => 1,
            'name' => 'Mateo González Pérez',
            'relationship' => 'Hijo',
            'age' => 8,
            'health_insurance' => 'Fonasa Tramo D',
            'medical_conditions' => 'Asma bronquial estacional, sin alergia a medicamentos.',
        ]);

        // 4. Load Saved Addresses
        \App\Models\SavedAddress::updateOrCreate([
            'id' => 'addr_1'
        ], [
            'user_id' => 1,
            'label' => 'Casa Principal',
            'text' => 'Calle Los Alerces 1420, depto 402, Providencia, Santiago',
        ]);

        \App\Models\SavedAddress::updateOrCreate([
            'id' => 'addr_2'
        ], [
            'user_id' => 1,
            'label' => 'Casa de Mamá',
            'text' => 'Avenida Vitacura 5410, Vitacura, Santiago',
        ]);

        // 5. Load Saved Payment Methods
        \App\Models\SavedPaymentMethod::updateOrCreate([
            'id' => 'pay_1'
        ], [
            'user_id' => 1,
            'type' => 'visa',
            'last4' => '4310',
        ]);

        \App\Models\SavedPaymentMethod::updateOrCreate([
            'id' => 'pay_2'
        ], [
            'user_id' => 1,
            'type' => 'mercadopago',
            'last4' => null,
        ]);

        \App\Models\SavedPaymentMethod::updateOrCreate([
            'id' => 'pay_3'
        ], [
            'user_id' => 1,
            'type' => 'mastercard',
            'last4' => '8821',
        ]);

        // 6. Load Past Services History
        \App\Models\PastService::updateOrCreate([
            'id' => 'past_1'
        ], [
            'user_id' => 1,
            'service_id' => 'medico',
            'service_title' => 'Atención Médica a Domicilio',
            'date' => '15 de Mayo 2026',
            'patient' => 'Margarita Sotomayor (Madre)',
            'price' => 40000,
            'status' => 'completed',
            'details' => 'Paciente diagnosticada con Bronquitis. Se indicó reposo por 5 días, kinesioterapia respiratoria de apoyo e inhaladores.',
            'professional' => 'Dra. Camila Rivera N. (Médico Internista)',
        ]);

        \App\Models\PastService::updateOrCreate([
            'id' => 'past_2'
        ], [
            'user_id' => 1,
            'service_id' => 'laboratorio',
            'service_title' => 'Toma de Muestras y Laboratorio',
            'date' => '02 de Abril 2026',
            'patient' => 'Usuario Principal',
            'price' => 19500,
            'status' => 'completed',
            'details' => 'Muestras procesadas de hemograma y perfil bioquímico. Resultados subidos al portal.',
            'professional' => 'Enf. Cristián Valenzuela',
        ]);
    }
}
