<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-11: Tarifas de traslados por georreferenciación.
 */
class TransportPricingTest extends TestCase
{
    use RefreshDatabase;

    private function makeServices(): void
    {
        ClinicalService::create([
            'id' => 'ambulancia',
            'title' => 'Ambulancia y Traslado',
            'short_title' => 'Traslado',
            'subtitle' => 'Traslado simple o medicalizado',
            'description' => 'Servicio de ambulancia',
            'base_price' => 18500,
            'base_eta' => '20 - 35',
            'requires_prescription' => false,
            'icon_name' => 'local_shipping',
            'warning_info' => 'Para emergencias críticas llame al 131.',
            'placeholder_text' => 'Indica el motivo del traslado',
        ]);
    }

    private function makeUser(): array
    {
        $user = User::create([
            'name' => 'Paciente Traslado',
            'email' => 'traslado@aura.cl',
            'password' => bcrypt('password123'),
        ]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_quote_transport_calculates_distance_and_fee_correctly(): void
    {
        [$user, $token] = $this->makeUser();

        // Coordinates: Providencia to Las Condes (~6.2 km)
        $originLat = -33.42628;
        $originLng = -70.61348;
        $destLat = -33.41142;
        $destLng = -70.57945;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/transport/quote?origin_lat=$originLat&origin_lng=$originLng&destination_lat=$destLat&destination_lng=$destLng&ambulance_type=basic");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'distance_km',
                'base_fee',
                'price_per_km',
                'transport_fee',
                'surcharge',
                'final_price',
                'ambulance_type',
            ]);

        $data = $response->json();
        $this->assertGreaterThan(2.0, $data['distance_km']);
        $this->assertEquals(18500, $data['base_fee']);
        $this->assertEquals(1200, $data['price_per_km']);
        $this->assertEquals('basic', $data['ambulance_type']);
        $this->assertGreaterThan(18500, $data['final_price']);
    }

    public function test_quote_transport_medicalized_uses_higher_base(): void
    {
        [$user, $token] = $this->makeUser();

        $originLat = -33.42628;
        $originLng = -70.61348;
        $destLat = -33.41142;
        $destLng = -70.57945;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson("/api/transport/quote?origin_lat=$originLat&origin_lng=$originLng&destination_lat=$destLat&destination_lng=$destLng&ambulance_type=medicalized");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(28500, $data['base_fee']);
        $this->assertEquals('medicalized', $data['ambulance_type']);
    }

    public function test_store_ambulance_booking_persists_coordinates_distance_and_fee(): void
    {
        $this->makeServices();
        [$user, $token] = $this->makeUser();

        $originLat = -33.42628;
        $originLng = -70.61348;
        $destLat = -33.41142;
        $destLng = -70.57945;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/bookings', [
                'service_id' => 'ambulancia',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234',
                'origin_address' => 'Av. Providencia 1234, Providencia',
                'destination_address' => 'Av. Las Condes 10000, Las Condes',
                'ambulance_type' => 'basic',
                'patient_lat' => $originLat,
                'patient_lng' => $originLng,
                'destination_lat' => $destLat,
                'destination_lng' => $destLng,
                'eta_minutes' => 25,
            ]);

        $response->assertStatus(201);
        $bookingId = $response->json('id');

        $booking = ServiceRequest::find($bookingId);
        $this->assertNotNull($booking);
        $this->assertEquals($destLat, $booking->destination_lat);
        $this->assertEquals($destLng, $booking->destination_lng);
        $this->assertNotNull($booking->distance_km);
        $this->assertGreaterThan(2.0, $booking->distance_km);
        $this->assertNotNull($booking->transport_fee);
        $this->assertGreaterThan(18500, $booking->final_price);
    }

    public function test_ambulance_booking_requires_destination_address(): void
    {
        $this->makeServices();
        [$user, $token] = $this->makeUser();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/bookings', [
                'service_id' => 'ambulancia',
                'patient_type' => 'self',
                'address_text' => 'Av. Providencia 1234',
                'origin_address' => 'Av. Providencia 1234, Providencia',
                'ambulance_type' => 'basic',
                'eta_minutes' => 25,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_address']);
    }
}
