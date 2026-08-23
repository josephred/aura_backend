<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and issue an API token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($validated);

        $token = $user->createToken('aura-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Authenticate a user and issue an API token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $rawEmail = trim(mb_strtolower($validated['email']));
        $unaccented = strtr($rawEmail, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $parts = explode('@', $unaccented);
        $dotless = str_replace('.', '', $parts[0]) . (isset($parts[1]) ? '@' . $parts[1] : '');
        $dottedMap = [
            'camilarivera@aura.cl' => 'camila.rivera@aura.cl',
            'camila.rivera@aura.cl' => 'camilarivera@aura.cl',
            'sebastianleyton@aura.cl' => 'sebastian.leyton@aura.cl',
            'sebastian.leyton@aura.cl' => 'sebastianleyton@aura.cl',
            'sebastián.leyton@aura.cl' => 'sebastian.leyton@aura.cl',
            'mariajosediaz@aura.cl' => 'maria.jose.diaz@aura.cl',
            'maria.jose.diaz@aura.cl' => 'mariajosediaz@aura.cl',
            'patriciajara@aura.cl' => 'patricia.jara@aura.cl',
            'patricia.jara@aura.cl' => 'patriciajara@aura.cl',
            'laboratorista@aura.cl' => 'laboratorio@aura.cl',
            'laboratorio@aura.cl' => 'laboratorista@aura.cl',
        ];

        $candidates = array_unique(array_filter([
            $rawEmail,
            $unaccented,
            $dotless,
            $dottedMap[$rawEmail] ?? null,
            $dottedMap[$unaccented] ?? null,
            $dottedMap[$dotless] ?? null,
        ]));

        $user = User::whereIn('email', $candidates)->first();

        // Si el usuario existe como profesional en la base de datos (con o sin cuenta User previa)
        $professional = Professional::whereIn('email', $candidates)->first();
        if ($professional && Hash::check($validated['password'], $professional->password)) {
            if (!$user) {
                $user = User::firstOrNew(['email' => $professional->email ?? $candidates[0]]);
            }
            $user->name = $professional->name;
            $user->password = $professional->password;
            $user->forceFill([
                'role' => $professional->role === 'admin' ? 'operator_admin' : 'doctor_provider',
                'is_test_account' => true,
                'professional_id' => $professional->id,
            ])->save();
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales ingresadas no son válidas.'],
            ]);
        }

        $token = $user->createToken('aura-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
