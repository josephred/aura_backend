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
}
