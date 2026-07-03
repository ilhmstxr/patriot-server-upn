<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Daftarkan middleware secara global khusus untuk API
        // $middleware->api(append: [
        //     \App\Http\Middleware\ForceJsonResponse::class,
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->ajax() || $request->header('X-Livewire')) {
                return response()->json([
                    'status'    => 'error',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'message'   => $e->getMessage(),
                    'exception' => get_class($e),
                    'file'      => str_replace(base_path(), '', $e->getFile()), // Hide absolute server path
                    'line'      => $e->getLine(),
                ], 500);
            }
        });
    })->create();
