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
        return $this->createGenericPreference(
            $booking->id,
            $booking->service_id,
            "Aura Salud — {$serviceTitle}",
            'Atención clínica a domicilio',
            (float) $booking->final_price,
            ['booking_id' => $booking->id, 'user_id' => $booking->user_id],
        );
    }

    /**
     * Create a Checkout Pro preference for any payable entity.
     * Returns ['id' => ..., 'init_point' => ...] or null on failure.
     */
    public function createGenericPreference(
        string $externalReference,
        string $itemId,
        string $title,
        string $description,
        float $price,
        array $metadata = [],
    ): ?array {
        $payload = [
            'items' => [[
                'id' => $itemId,
                'title' => $title,
                'description' => $description,
                'category_id' => 'services',
                'quantity' => 1,
                'currency_id' => 'CLP',
                'unit_price' => $price,
            ]],
            'external_reference' => $externalReference,
            'statement_descriptor' => 'AURA SALUD',
            'metadata' => $metadata,
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
                'reference' => $externalReference,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MercadoPago unreachable while creating preference', [
                'reference' => $externalReference,
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
        } catch (\Throwable) {
            Log::warning('MercadoPago payment search failed', ['ref' => $externalReference]);
        }

        return null;
    }

    /**
     * Create a recurring preapproval (subscription plan payment).
     * Returns ['id' => ..., 'init_point' => ...] or null.
     */
    public function createPreapproval(
        string $payerEmail,
        string $planTitle,
        float $monthlyPrice,
        string $externalReference,
        ?string $backUrl = null,
    ): ?array {
        $payload = [
            'payer_email' => $payerEmail,
            'back_url' => $backUrl ?? config('app.url') . '/subscription/success',
            'reason' => "Aura Salud — {$planTitle}",
            'external_reference' => $externalReference,
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => $monthlyPrice,
                'currency_id' => 'CLP',
            ],
            'status' => 'pending',
        ];

        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->post(self::API_BASE . '/preapproval', $payload);

            if ($response->successful()) {
                return [
                    'id' => $response->json('id'),
                    'init_point' => $response->json('init_point'),
                ];
            }

            Log::warning('MercadoPago preapproval creation failed', [
                'ref' => $externalReference,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MercadoPago unreachable while creating preapproval', [
                'ref' => $externalReference,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch preapproval details.
     */
    public function getPreapproval(string $preapprovalId): ?array
    {
        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->get(self::API_BASE . "/preapproval/{$preapprovalId}");

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('MercadoPago getPreapproval failed', ['id' => $preapprovalId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Cancel a recurring subscription in Mercado Pago.
     */
    public function cancelPreapproval(string $preapprovalId): bool
    {
        try {
            $response = Http::withToken(config('services.mercadopago.access_token'))
                ->timeout(10)
                ->put(self::API_BASE . "/preapproval/{$preapprovalId}", [
                    'status' => 'cancelled',
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('MercadoPago cancelPreapproval failed', ['id' => $preapprovalId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
