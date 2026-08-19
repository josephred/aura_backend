<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El importe de una solicitud lo fija el servidor.
 *
 * Llegaba en el cuerpo de la petición y se guardaba tal cual. Ese número
 * gobierna dos cosas a la vez —lo que se le cobra al paciente en la pasarela y
 * lo que se le devenga al prestador—, así que cualquiera que editara la
 * petición elegía las dos. Con `final_price: 1` se compraba una ambulancia por
 * un peso y el profesional devengaba cero.
 */
class BookingPricingTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): array
    {
        $user = User::create([
            'name' => 'Paciente Precio',
            'email' => 'precio@aura.cl',
            'password' => 'password123',
        ]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeService(string $id, string $title, int $basePrice): void
    {
        ClinicalService::create([
            'id' => $id,
            'title' => $title,
            'short_title' => $title,
            'subtitle' => 'Prestación de prueba',
            'description' => 'Prestación de prueba',
            'base_price' => $basePrice,
            'base_eta' => '30 - 45',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => 'Sin urgencias vitales.',
            'placeholder_text' => 'Ej. fiebre alta y tos seca',
        ]);
    }

    public function test_a_manipulated_price_is_ignored_in_favour_of_the_catalogue(): void
    {
        $this->makeService('medico', 'Médico a domicilio', 40000);
        [, $token] = $this->patient();

        $response = $this->withToken($token)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'symptoms_description' => 'dolor de cabeza y fiebre',
            // Lo que mandaría alguien editando la petición.
            'final_price' => 1,
            'eta_minutes' => 30,
        ]);

        $response->assertStatus(201);

        // 40000 de catálogo + 15 % de recargo de plataforma.
        $this->assertSame(46000, (int) ServiceRequest::find($response->json('id'))->final_price);
    }

    public function test_a_negative_price_is_rejected_by_validation(): void
    {
        $this->makeService('medico', 'Médico a domicilio', 40000);
        [, $token] = $this->patient();

        $this->withToken($token)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'symptoms_description' => 'dolor de cabeza y fiebre',
            'final_price' => -5000,
            'eta_minutes' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('final_price');
    }

    public function test_the_medicalised_ambulance_costs_more_than_the_basic_one(): void
    {
        $this->makeService('ambulancia', 'Ambulancia de traslado', 18500);
        [, $token] = $this->patient();

        $payload = [
            'service_id' => 'ambulancia',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'destination_address' => 'Clínica Alemana, Vitacura',
            'final_price' => 1,
            'eta_minutes' => 30,
        ];

        $basic = $this->withToken($token)
            ->postJson('/api/bookings', $payload + ['ambulance_type' => 'basic']);
        $basic->assertStatus(201);
        $basicRow = ServiceRequest::find($basic->json('id'));

        // 18500 + 15 %
        $this->assertSame(21275, (int) $basicRow->final_price);
        $this->assertSame('basic', $basicRow->ambulance_type);

        $medicalised = $this->withToken($token)
            ->postJson('/api/bookings', $payload + ['ambulance_type' => 'medicalized']);
        $medicalised->assertStatus(201);

        // 28500 + 15 %. La variante medicalizada no tiene fila propia en el
        // catálogo: su precio vive en config('aura.ambulance').
        $this->assertSame(32775, (int) ServiceRequest::find($medicalised->json('id'))->final_price);
    }

    public function test_the_gateway_is_charged_the_server_price_not_the_client_one(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref_precio',
                'init_point' => 'https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref_precio',
            ], 201),
        ]);

        $this->makeService('medico', 'Médico a domicilio', 40000);
        [, $token] = $this->patient();

        $this->withToken($token)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'symptoms_description' => 'dolor de cabeza y fiebre',
            'final_price' => 1,
            'eta_minutes' => 30,
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/checkout/preferences')
                && (int) $request['items'][0]['unit_price'] === 46000;
        });
    }

    public function test_the_surcharge_is_configurable_and_not_hardcoded_in_the_app(): void
    {
        // El recargo vivía como `_commissionRate = 0.15` en el cliente Flutter,
        // bajo el comentario "Simulator Parameters". Ahora manda el servidor.
        config(['aura.patient_surcharge_bps' => 0]);

        $this->makeService('medico', 'Médico a domicilio', 40000);
        [, $token] = $this->patient();

        $response = $this->withToken($token)->postJson('/api/bookings', [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Providencia 1234, Providencia',
            'symptoms_description' => 'dolor de cabeza y fiebre',
            'final_price' => 999999,
            'eta_minutes' => 30,
        ]);

        $response->assertStatus(201);
        $this->assertSame(40000, (int) ServiceRequest::find($response->json('id'))->final_price);
    }
}
