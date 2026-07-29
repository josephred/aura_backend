<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the staff endpoints consumed by the mobile app.
 *
 * Requires a Sanctum-authenticated account whose role is clinical staff, and —
 * for professionals — an actual link to a `professionals` row. Without that
 * link there is no clinical identity to act as, so the request is refused
 * rather than silently behaving like an unscoped admin.
 */
class StaffApiAuth
{
    public function handle(Request $request, Closure $next, ?string $requiredRole = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        if (!$user->isStaff()) {
            return response()->json(['error' => 'Esta sección es solo para personal de Aura'], 403);
        }

        if ($requiredRole === 'operator' && !$user->isOperator()) {
            return response()->json(['error' => 'Solo operadores/administradores'], 403);
        }

        if ($user->role === 'doctor_provider' && empty($user->professional_id)) {
            return response()->json([
                'error' => 'Tu cuenta aún no está vinculada a una ficha de profesional. '
                    . 'Contacta a la coordinación de Aura.',
            ], 403);
        }

        return $next($request);
    }
}
