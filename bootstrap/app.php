<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ngrok/reverse proxies so Laravel sees the request as HTTPS
        // (X-Forwarded-Proto). Without this the doctor portal breaks behind
        // the tunnel: wrong scheme in generated URLs, insecure session cookies.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'staff.auth' => \App\Http\Middleware\StaffAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
