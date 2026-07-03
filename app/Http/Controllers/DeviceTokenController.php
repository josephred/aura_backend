<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register (or re-assign) an FCM device token for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|string|in:android,ios,web',
        ]);

        // A token identifies a device: if it re-registers under another
        // account, move it instead of duplicating it
        $deviceToken = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => auth()->id(),
                'platform' => $validated['platform'] ?? 'android',
            ]
        );

        return response()->json($deviceToken, 201);
    }

    /**
     * Remove a device token (e.g. on logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('user_id', auth()->id())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Token eliminado']);
    }
}
