<?php

namespace Tests\Feature;

use App\Models\ClinicalService;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): array
    {
        $user = User::create([
            'name' => 'Paciente Test',
            'email' => 'paciente@aura.cl',
            'password' => 'password123',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        ClinicalService::create([
            'id' => 'medico',
            'title' => 'Médico a Domicilio',
            'short_title' => 'Médico',
            'subtitle' => 'Consulta general',
            'description' => 'Consulta médica general en tu hogar',
            'base_price' => 45000,
            'base_eta' => '45-60 min',
            'requires_prescription' => false,
            'icon_name' => 'medical_services',
            'warning_info' => '',
            'placeholder_text' => '',
        ]);

        return [$user, $token];
    }

    private function bookingPayload(): array
    {
        return [
            'service_id' => 'medico',
            'patient_type' => 'self',
            'address_text' => 'Av. Siempre Viva 742',
            'final_price' => 45000,
            'eta_minutes' => 45,
        ];
    }

    public function test_booking_without_gateway_is_auto_accepted(): void
    {
        [, $token] = $this->actingUser();

        $response = $this->withToken($token)->postJson('/api/bookings', $this->bookingPayload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('current_step', 1)
            ->assertJsonPath('payment_url', null);
    }

    public function test_booking_with_gateway_starts_pending_payment(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref_123',
                'init_point' => 'https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref_123',
            ], 201),
        ]);

        [, $token] = $this->actingUser();

        $response = $this->withToken($token)->postJson('/api/bookings', $this->bookingPayload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('current_step', 0)
            ->assertJsonPath('payment_status', 'pending')
            ->assertJsonPath('payment_preference_id', 'pref_123');

        $this->assertStringContainsString('mercadopago', $response->json('payment_url'));

        // No chat channel until the payment is approved
        $this->assertDatabaseCount('chat_messages', 0);

        Http::assertSent(function ($request) use ($response) {
            return str_contains($request->url(), '/checkout/preferences')
                && $request['external_reference'] === $response->json('id');
        });
    }

    public function test_webhook_approves_booking_and_opens_chat(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);

        [$user, ] = $this->actingUser();

        $booking = ServiceRequest::create([
            'id' => 'req_pay1',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'pending_payment',
            'patient_type' => 'self',
            'address_text' => 'Av. Siempre Viva 742',
            'payment_method' => 'mercadopago',
            'final_price' => 45000,
            'start_time' => '10:00',
            'eta_minutes' => 45,
            'current_step' => 0,
            'payment_status' => 'pending',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments/999' => Http::response([
                'id' => 999,
                'status' => 'approved',
                'external_reference' => 'req_pay1',
            ]),
        ]);

        $this->postJson('/api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '999'],
        ])->assertStatus(200)->assertJsonPath('message', 'ok');

        $booking->refresh();
        $this->assertSame('accepted', $booking->status);
        $this->assertSame(1, $booking->current_step);
        $this->assertSame('approved', $booking->payment_status);
        $this->assertSame('999', $booking->payment_id);
        $this->assertDatabaseCount('chat_messages', 2);

        // Webhook is idempotent: replaying it must not duplicate chat messages
        $this->postJson('/api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '999'],
        ])->assertStatus(200);
        $this->assertDatabaseCount('chat_messages', 2);
    }

    public function test_forged_webhook_cannot_approve_booking(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);

        [$user, ] = $this->actingUser();

        ServiceRequest::create([
            'id' => 'req_pay2',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'pending_payment',
            'patient_type' => 'self',
            'address_text' => 'Av. Siempre Viva 742',
            'payment_method' => 'mercadopago',
            'final_price' => 45000,
            'start_time' => '10:00',
            'eta_minutes' => 45,
            'current_step' => 0,
            'payment_status' => 'pending',
        ]);

        // Mercado Pago says this payment is still pending, regardless of the body
        Http::fake([
            'api.mercadopago.com/v1/payments/555' => Http::response([
                'id' => 555,
                'status' => 'pending',
                'external_reference' => 'req_pay2',
            ]),
        ]);

        $this->postJson('/api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '555'],
        ])->assertStatus(200);

        $this->assertSame('pending_payment', ServiceRequest::find('req_pay2')->status);
    }

    public function test_payment_status_poll_approves_from_search(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);

        [$user, $token] = $this->actingUser();

        ServiceRequest::create([
            'id' => 'req_pay3',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'pending_payment',
            'patient_type' => 'self',
            'address_text' => 'Av. Siempre Viva 742',
            'payment_method' => 'mercadopago',
            'final_price' => 45000,
            'start_time' => '10:00',
            'eta_minutes' => 45,
            'current_step' => 0,
            'payment_status' => 'pending',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [
                    ['id' => 777, 'status' => 'approved', 'external_reference' => 'req_pay3'],
                ],
            ]),
        ]);

        $this->withToken($token)
            ->getJson('/api/bookings/req_pay3/payment-status')
            ->assertStatus(200)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('payment_status', 'approved');
    }

    public function test_other_user_cannot_poll_foreign_booking(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);

        [$user, ] = $this->actingUser();

        ServiceRequest::create([
            'id' => 'req_pay4',
            'user_id' => $user->id,
            'service_id' => 'medico',
            'status' => 'pending_payment',
            'patient_type' => 'self',
            'address_text' => 'Av. Siempre Viva 742',
            'payment_method' => 'mercadopago',
            'final_price' => 45000,
            'start_time' => '10:00',
            'eta_minutes' => 45,
            'current_step' => 0,
            'payment_status' => 'pending',
        ]);

        $other = User::create(['name' => 'Otro', 'email' => 'otro@aura.cl', 'password' => 'password123']);
        $otherToken = $other->createToken('test')->plainTextToken;

        $this->withToken($otherToken)
            ->getJson('/api/bookings/req_pay4/payment-status')
            ->assertStatus(404);
    }
}
