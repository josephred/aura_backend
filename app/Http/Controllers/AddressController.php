<?php

namespace App\Http\Controllers;

use App\Models\SavedAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AddressController extends Controller
{
    /**
     * Display a listing of saved addresses.
     */
    public function index(): JsonResponse
    {
        return response()->json(SavedAddress::where('user_id', auth()->id())->get());
    }

    /**
     * Store a newly created address in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'label' => 'required|string',
            'text' => 'required|string',
        ]);

        $validated['user_id'] = auth()->id();

        $address = SavedAddress::create($validated);

        return response()->json($address, 201);
    }

    /**
     * Update the specified address in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $address = SavedAddress::where('user_id', auth()->id())->where('id', $id)->first();
        if (!$address) {
            return response()->json(['error' => 'Address not found'], 404);
        }

        $validated = $request->validate([
            'label' => 'required|string',
            'text' => 'required|string',
        ]);

        $address->update($validated);

        return response()->json($address);
    }

    /**
     * Remove the specified address from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = SavedAddress::where('user_id', auth()->id())->where('id', $id)->delete();

        if ($deleted) {
            return response()->json(['message' => 'Address deleted successfully']);
        }

        return response()->json(['error' => 'Address not found'], 404);
    }
}
