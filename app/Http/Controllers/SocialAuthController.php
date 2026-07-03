<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SocialAccount;
use App\Services\SocialTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    /**
     * Authenticate or register a user via a social network provider.
     *
     * The client only sends the provider name and the provider-issued
     * credential (Google id_token / Facebook access token). Identity is
     * taken exclusively from the provider's verification response.
     */
    public function loginOrRegister(Request $request, SocialTokenVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:google,facebook',
            'credential' => 'required|string',
        ]);

        if (!$verifier->isProviderConfigured($validated['provider'])) {
            return response()->json([
                'message' => 'El inicio de sesión con ' . $validated['provider'] . ' no está disponible por el momento.',
            ], 503);
        }

        $identity = $verifier->verify($validated['provider'], $validated['credential']);

        if ($identity === null) {
            return response()->json([
                'message' => 'No pudimos validar tu identidad con ' . $validated['provider'] . '. Intenta nuevamente.',
            ], 401);
        }

        // 1. Look for existing social account link
        $socialAccount = SocialAccount::where('provider', $validated['provider'])
            ->where('provider_id', $identity['provider_id'])
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
            $token = $user->createToken('aura-app')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
            ]);
        }

        // 2. No social link. Look for existing user by verified email
        $user = User::where('email', $identity['email'])->first();

        if (!$user) {
            // 3. User does not exist, create new user (random password since they log in via social)
            $user = User::create([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'password' => bcrypt(Str::random(24)),
            ]);
        }

        // 4. Create the social account link
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $validated['provider'],
            'provider_id' => $identity['provider_id'],
        ]);

        $token = $user->createToken('aura-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
