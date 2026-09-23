<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS за nginx: иначе session cookie / CSRF ломаются (419 на iPhone Safari)
        $middleware->trustProxies(at: '*');

        // Server-to-server из Lead Control (Bearer DESK_INTERNAL_TOKEN), без CSRF-сессии
        $middleware->validateCsrfTokens(except: [
            'desk/internal/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Сессия истекла. Обновите страницу и войдите снова.',
                ], 419);
            }

            return redirect()
                ->route('hub.login')
                ->with('warning', 'Сессия истекла. Обновите страницу и войдите снова.');
        });
    })->create();
