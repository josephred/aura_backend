<?php

namespace App\Services;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoService
{
    private const API_BASE = 'https://api.mercadopago.com';

    public function isConfigured(): bool
    {
        return !empty(config('services.mercadopago.access_token'));
    }

    /**
     * Create a Checkout Pro preference for a booking.
     * Returns ['id' => ..., 'init_point' => ...] or null on failure.
     */
    public function createPreference(ServiceRequest $booking, string $serviceTitle): ?array
    {
        $payload = [
            'items' => [[
                'id' => $booking->service_id,
                'title' => "Aura Salud — {$serviceTitle}",
                'description' => 'Atención clínica a domicilio',
                'category_id' => 'services',
                'quantity' => 1,
                'currency_id' => 'CLP',
                'unit_price' => (float) $booking->final_price,
            ]],
            'external_reference' => $booking->id,
            'statement_descriptor' => 'AURA SALUD',
            'metadata' => [
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
            ],
        ];

        $webhookUrl = config('services.mercadopago.webhook_url');
        if (!empty($webhookUrl)) {
            $payload['notification_url'] = $webhookUrl;
        }

        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->post(self::API_BASE . '/checkout/preferences', $payload);

            if ($response->successful()) {
                return [
                    'id' => $response->json('id'),
                    'init_point' => $response->json('init_point'),
                ];
            }

            Log::warning('MercadoPago preference creation failed', [
                'booking' => $booking->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MercadoPago unreachable while creating preference', [
                'booking' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch a payment by its Mercado Pago id.
     */
    public function getPayment(string $paymentId): ?array
    {
        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->get(self::API_BASE . "/v1/payments/{$paymentId}");

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('MercadoPago getPayment failed', ['payment' => $paymentId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Search for an approved payment tied to a booking (external_reference).
     */
    public function findApprovedPayment(string $externalReference): ?array
    {
        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->get(self::API_BASE . '/v1/payments/search', [
                    'external_reference' => $externalReference,
                    'sort' => 'date_created',
                    'criteria' => 'desc',
                ]);

            if (!$response->successful()) {
                return null;
            }

            foreach ($response->json('results', []) as $payment) {
                if (($payment['status'] ?? null) === 'approved') {
                    return $payment;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MercadoPago payment search failed', ['ref' => $externalReference, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
