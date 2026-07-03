<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    /**
     * Authenticate or register a user via a social network provider.
     */
    public function loginOrRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:google,facebook,instagram',
            'provider_id' => 'required|string',
            'email' => 'required|string|email|max:255',
            'name' => 'required|string|max:255',
        ]);

        // 1. Look for existing social account link
        $socialAccount = SocialAccount::where('provider', $validated['provider'])
            ->where('provider_id', $validated['provider_id'])
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
            $token = $user->createToken('aura-app')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
            ]);
        }

        // 2. No social link. Look for existing user by email
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            // 3. User does not exist, create new user (null/random password since they log in via social)
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt(Str::random(24)),
            ]);
        }

        // 4. Create the social account link
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $validated['provider'],
            'provider_id' => $validated['provider_id'],
        ]);

        $token = $user->createToken('aura-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
