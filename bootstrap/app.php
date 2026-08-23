<?php

use App\Exceptions\MediaAssetInUse;
use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (MediaAssetInUse $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'code' => MediaAssetInUse::CODE,
                'message' => MediaAssetInUse::PUBLIC_MESSAGE,
                'usage' => $exception->usage->toArray(),
            ], 409);
        });
        $exceptions->render(function (UnsupportedWebsiteSchemaVersion $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'code' => UnsupportedWebsiteSchemaVersion::CODE,
                'message' => UnsupportedWebsiteSchemaVersion::PUBLIC_MESSAGE,
            ], 409);
        });
    })->create();
