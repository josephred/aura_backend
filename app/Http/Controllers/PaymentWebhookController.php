<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ServiceRequest;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Handle Mercado Pago payment notifications.
     *
     * The notification only carries the payment id; the actual payment is
     * always re-fetched from the Mercado Pago API with our access token, so
     * a forged webhook body cannot approve a booking.
     */
    public function mercadoPago(Request $request, MercadoPagoService $mercadoPago): JsonResponse
    {
        $type = $request->input('type', $request->input('topic'));
        $paymentId = $request->input('data.id', $request->input('id'));

        if (in_array($type, ['subscription_preapproval', 'preapproval'], true)) {
            $preapproval = $mercadoPago->getPreapproval((string) $paymentId);
            if ($preapproval) {
                $status = $preapproval['status'] ?? 'pending';
                $extRef = $preapproval['external_reference'] ?? null;
                $subscription = $extRef
                    ? \App\Models\UserSubscription::find($extRef)
                    : \App\Models\UserSubscription::where('mercadopago_preapproval_id', $paymentId)->first();

                if ($subscription) {
                    $subscription->update([
                        'status' => $status === 'authorized' ? 'active' : ($status === 'cancelled' ? 'cancelled' : 'pending'),
                        'mercadopago_preapproval_id' => $preapproval['id'] ?? $subscription->mercadopago_preapproval_id,
                    ]);

                    return response()->json(['message' => 'subscription updated']);
                }
            }

            return response()->json(['message' => 'subscription not found']);
        }

        if ($type !== 'payment' || empty($paymentId)) {
            return response()->json(['message' => 'ignored']);
        }

        $payment = $mercadoPago->getPayment((string) $paymentId);

        if (!$payment || ($payment['status'] ?? null) !== 'approved') {
            return response()->json(['message' => 'not approved yet']);
        }

        $reference = $payment['external_reference'] ?? null;

        $booking = $reference ? ServiceRequest::find($reference) : null;
        if ($booking) {
            app(BookingController::class)->approvePayment($booking, (string) $paymentId);
            return response()->json(['message' => 'ok']);
        }

        $appointment = $reference ? Appointment::find($reference) : null;
        if ($appointment) {
            app(AppointmentController::class)->approvePayment($appointment, (string) $paymentId);
            return response()->json(['message' => 'ok']);
        }

        Log::warning('MercadoPago webhook for unknown reference', ['payment' => $paymentId, 'ref' => $reference]);
        return response()->json(['message' => 'reference not found']);
    }
}
