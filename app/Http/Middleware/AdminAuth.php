<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the operations panel to admin staff.
 *
 * The clinical portal (/doctor) and the operations panel (/admin) are separate
 * areas: a professional never sees administration, and an admin gets the
 * operations panel as their landing page instead of a doctor's dashboard.
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('staff_authenticated')) {
            if ($request->expectsJson() || $request->is('admin/api/*')) {
                return response()->json(['error' => 'No autorizado'], 401);
            }

            return redirect('/doctor/login');
        }

        if ($request->session()->get('staff_role') !== 'admin') {
            if ($request->expectsJson() || $request->is('admin/api/*')) {
                return response()->json(['error' => 'Solo administradores'], 403);
            }

            return redirect('/doctor');
        }

        return $next($request);
    }
}
