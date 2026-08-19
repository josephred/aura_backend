<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\User;
use App\Rules\AtLeastTwoSymptoms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The consultation reason opens the clinical history, so it must name at least
 * two symptoms before a request is accepted.
 */
class SymptomValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeMedicalService(): void
    {
        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Médico a domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Servicio médico',
            'base_price' => 25000,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            // Ambas columnas son NOT NULL en el catálogo: `warning_info` es el
            // aviso de seguridad que la app muestra antes de solicitar, así que
            // el esquema no admite un servicio sin él.
            'warning_info' => 'Servicio no apto para urgencias de riesgo vital.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    private function token(): string
    {
        $user = User::create([
            'name' => 'Paciente',
            'email' => 'sintomas@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        return $user->createToken('test')->plainTextToken;
    }

    /** @return array<int, array{0:string,1:bool}> */
    public static function symptomSamples(): array
    {
        return [
            'dos separados por y' => ['dolor de cabeza y fiebre', true],
            'dos separados por coma' => ['fiebre alta, tos seca', true],
            'punto y coma' => ['tos; congestión nasal', true],
            'barra' => ['dolor abdominal / náuseas', true],
            'salto de línea' => ["dolor de cabeza\nmareos", true],
            'mayúsculas' => ['Fiebre Alta Y Tos', true],

            'uno solo' => ['dolor de cabeza', false],
            'una palabra' => ['fiebre', false],
            'vacío' => ['', false],
            'solo espacios' => ['   ', false],
            'repetido no cuenta' => ['fiebre y fiebre', false],
            'coma colgando' => ['fiebre, ', false],
            // The "e" inside "cabeza" must not be treated as a separator.
            'e interna no separa' => ['cabeza', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('symptomSamples')]
    public function test_rule_recognises_two_symptoms(string $text, bool $expected): void
    {
        $this->assertSame(
            $expected,
            AtLeastTwoSymptoms::passes($text),
            // Las llaves son obligatorias: PHP admite bytes altos en los
            // nombres de variable, así que «$text»» se interpolaba como la
            // variable `$text»` —inexistente— en lugar de `$text`.
            "Texto evaluado: «{$text}»",
        );
    }

    public function test_booking_rejects_a_single_symptom(): void
    {
        $this->makeMedicalService();

        $this->withToken($this->token())
            ->postJson('/api/bookings', [
                'service_id' => 'medico',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234, Providencia',
                'symptoms_description' => 'fiebre',
                'final_price' => 25000,
                'eta_minutes' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptoms_description');
    }

    public function test_booking_rejects_an_empty_reason(): void
    {
        $this->makeMedicalService();

        $this->withToken($this->token())
            ->postJson('/api/bookings', [
                'service_id' => 'medico',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234, Providencia',
                'final_price' => 25000,
                'eta_minutes' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptoms_description');
    }

    public function test_booking_accepts_two_symptoms(): void
    {
        $this->makeMedicalService();

        $this->withToken($this->token())
            ->postJson('/api/bookings', [
                'service_id' => 'medico',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234, Providencia',
                'symptoms_description' => 'dolor de cabeza y fiebre',
                'final_price' => 25000,
                'eta_minutes' => 30,
            ])
            ->assertStatus(201);
    }

    public function test_booking_accepts_single_exam_description_for_radiology(): void
    {
        ClinicalService::create([
            'id' => 'radiologia',
            'title' => 'Radiografía a domicilio',
            'short_title' => 'Radiología',
            'subtitle' => 'Rayos X',
            'description' => 'Servicio de imágenes',
            'base_price' => 45000,
            'base_eta' => '45 - 60',
            'requires_prescription' => true,
            'icon_name' => 'camera',
            'warning_info' => 'Requiere orden médica previa.',
            'placeholder_text' => 'Indica qué radiografía necesitas',
        ]);

        $this->withToken($this->token())
            ->postJson('/api/bookings', [
                'service_id' => 'radiologia',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234, Providencia',
                'symptoms_description' => 'Radiografía de tórax',
                'final_price' => 45000,
                'eta_minutes' => 45,
            ])
            ->assertStatus(201);
    }
}
