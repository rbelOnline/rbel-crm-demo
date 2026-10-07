<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\QueryException;
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
        // Sanctum SPA auth: session cookies + CSRF for first-party requests.
        $middleware->statefulApi();

        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Surface FK violations and stored-procedure SIGNALs as clean 4xx errors.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match ($e->errorInfo[1] ?? null) {
                1451 => response()->json(['message' => 'This record is still referenced by other records and cannot be removed.'], 409),
                1644 => response()->json(['message' => $e->errorInfo[2] ?? 'Invalid analytics request.'], 422),
                default => null,
            };
        });
    })->create();
