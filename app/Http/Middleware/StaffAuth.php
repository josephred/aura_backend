<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffAuth
{
    /**
     * Require an authenticated staff session for the doctor portal.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('staff_authenticated')) {
            if ($request->expectsJson() || $request->is('doctor/api/*')) {
                return response()->json(['error' => 'No autorizado'], 401);
            }

            return redirect('/doctor/login');
        }

        return $next($request);
    }
}
