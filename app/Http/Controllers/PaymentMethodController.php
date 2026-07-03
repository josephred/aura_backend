<?php

namespace App\Http\Controllers;

use App\Models\SavedPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentMethodController extends Controller
{
    /**
     * Display a listing of saved payment methods.
     */
    public function index(): JsonResponse
    {
        return response()->json(SavedPaymentMethod::where('user_id', auth()->id())->get());
    }

    /**
     * Store a newly created payment method in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'type' => 'required|string|in:visa,mastercard,mercadopago',
            'last4' => 'nullable|string|size:4',
        ]);

        $validated['user_id'] = auth()->id();

        $paymentMethod = SavedPaymentMethod::create($validated);

        return response()->json($paymentMethod, 201);
    }

    /**
     * Remove the specified payment method from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = SavedPaymentMethod::where('user_id', auth()->id())->where('id', $id)->delete();

        if ($deleted) {
            return response()->json(['message' => 'Payment method deleted successfully']);
        }

        return response()->json(['error' => 'Payment method not found'], 404);
    }
}
