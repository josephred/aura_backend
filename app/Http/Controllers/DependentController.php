<?php

namespace App\Http\Controllers;

use App\Models\Dependent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DependentController extends Controller
{
    /**
     * Display a listing of dependents.
     */
    public function index(): JsonResponse
    {
        return response()->json(Dependent::where('user_id', auth()->id())->get());
    }

    /**
     * Store a newly created dependent in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'name' => 'required|string',
            'relationship' => 'required|string',
            'age' => 'required|integer',
            'health_insurance' => 'required|string',
            'medical_conditions' => 'nullable|string',
        ]);

        $validated['user_id'] = auth()->id();

        $dependent = Dependent::create($validated);

        return response()->json($dependent, 201);
    }

    /**
     * Update the specified dependent in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $dependent = Dependent::where('user_id', auth()->id())->where('id', $id)->first();
        if (!$dependent) {
            return response()->json(['error' => 'Dependent not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'relationship' => 'required|string',
            'age' => 'required|integer',
            'health_insurance' => 'required|string',
            'medical_conditions' => 'nullable|string',
        ]);

        $dependent->update($validated);

        return response()->json($dependent);
    }

    /**
     * Remove the specified dependent from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $deleted = Dependent::where('user_id', auth()->id())->where('id', $id)->delete();

        if ($deleted) {
            return response()->json(['message' => 'Dependent deleted successfully']);
        }

        return response()->json(['error' => 'Dependent not found'], 404);
    }
}
