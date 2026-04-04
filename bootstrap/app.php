<?php

use App\Http\Middleware\BlockSuspiciousAgents;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
  ->withRouting(
    web: __DIR__ . '/../routes/web.php',
    api: __DIR__ . '/../routes/api.php',
    commands: __DIR__ . '/../routes/console.php',
    health: '/up',
  )
  ->withMiddleware(function (Middleware $middleware): void {
    // Habilita el manejo de estado para Sanctum en la API
    $middleware->statefulApi();

    // Excluye las rutas de API del chequeo CSRF
    // (Las APIs usan Bearer Token, no cookies de sesión)
    $middleware->validateCsrfTokens(except: [
      'api/*',
    ]);

    // Bloquea User-Agents sospechosos o vacíos en todas las rutas API
    $middleware->appendToGroup('api', [
      BlockSuspiciousAgents::class,
    ]);
  })
  ->withExceptions(function (Exceptions $exceptions): void {
    // Captura errores de autenticación en rutas de API y devuelve JSON estandarizado
    $exceptions->render(function (AuthenticationException $e, Request $request) {
      if ($request->is('api/*')) {
        return response()->json([
          'status' => 'error',
          'message' => 'No autenticado.',
          'data'    => null,
          'errors'  => ['auth' => ['No autenticado.']],
        ], 401);
      }
    });
  })->create();
