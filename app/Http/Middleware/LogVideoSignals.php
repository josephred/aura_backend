<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Temporary diagnostic middleware: logs every request to video-signals
 * endpoints BEFORE the controller runs. This tells us definitively if the
 * Flutter POST reaches the server or dies en route.
 *
 * Remove once the video call issue is resolved.
 */
class LogVideoSignals
{
    public function handle(Request $request, Closure $next)
    {
        if (str_contains($request->path(), 'video-signals') && $request->isMethod('post')) {
            Log::warning('VIDEO-SIGNAL-POST-ARRIVED', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => $request->user()?->id ?? 'guest',
                'guard' => $request->is('api/*') ? 'sanctum' : 'web',
                'body_type' => $request->input('type'),
                'has_payload' => $request->has('payload'),
                'content_type' => $request->header('Content-Type'),
                'auth_header' => $request->hasHeader('Authorization') ? 'present' : 'missing',
            ]);
        }

        return $next($request);
    }
}
