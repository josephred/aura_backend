<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    /**
     * Catálogo público de planes de suscripción disponibles.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('active', true)->get();

        return response()->json($plans);
    }

    /**
     * Consulta el estado de suscripción del usuario autenticado.
     */
    public function current(): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        $subscription = UserSubscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending', 'past_due'])
            ->orderByDesc('created_at')
            ->first();

        if (!$subscription) {
            return response()->json([
                'has_subscription' => false,
                'subscription' => null,
            ]);
        }

        $included = $subscription->plan?->included_consultations ?? 0;
        $used = $subscription->consultations_used;
        $remaining = max(0, $included - $used);

        return response()->json([
            'has_subscription' => $subscription->status === 'active',
            'status' => $subscription->status,
            'subscription' => $subscription,
            'plan' => $subscription->plan,
            'included_consultations' => $included,
            'used_consultations' => $used,
            'remaining_consultations' => $remaining,
            'discount_percentage' => $subscription->plan?->discount_percentage ?? 0,
            'next_billing_date' => $subscription->next_billing_date?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Inicia o renueva la suscripción del usuario a un plan mediante Mercado Pago Preapproval.
     */
    public function subscribe(Request $request, MercadoPagoService $mpService): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|string|exists:subscription_plans,id',
            'back_url' => 'nullable|url',
        ]);

        $user = auth()->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        $subscriptionId = 'sub_' . now()->timestamp . '_' . Str::lower(Str::random(6));

        $preapproval = $mpService->createPreapproval(
            $user->email,
            $plan->name,
            (float) $plan->monthly_price,
            $subscriptionId,
            $validated['back_url'] ?? null,
        );

        $subscription = UserSubscription::create([
            'id' => $subscriptionId,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => $preapproval ? 'pending' : 'active',
            'mercadopago_preapproval_id' => $preapproval['id'] ?? null,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
            'consultations_used' => 0,
            'auto_renew' => true,
        ]);

        return response()->json([
            'subscription' => $subscription->fresh()->load('plan'),
            'init_point' => $preapproval['init_point'] ?? null,
            'mercadopago_preapproval_id' => $preapproval['id'] ?? null,
        ], 201);
    }

    /**
     * Cancela la renovación automática de la suscripción activa.
     */
    public function cancel(MercadoPagoService $mpService): JsonResponse
    {
        $user = auth()->user();
        $subscription = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No tienes una suscripción activa.'], 404);
        }

        if ($subscription->mercadopago_preapproval_id) {
            $mpService->cancelPreapproval($subscription->mercadopago_preapproval_id);
        }

        $subscription->update([
            'auto_renew' => false,
            'status' => 'cancelled',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tu suscripción ha sido cancelada.',
            'subscription' => $subscription,
        ]);
    }
}
