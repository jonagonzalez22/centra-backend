<?php

use App\Http\Middleware\BlockSuspiciousAgents;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
  ->withRouting(
    web: __DIR__ . '/../routes/web.php',
    api: __DIR__ . '/../routes/api.php',
    commands: __DIR__ . '/../routes/console.php',
    health: '/up',
  )
  ->withMiddleware(function (Middleware $middleware): void {
    $middleware->statefulApi();

    $middleware->validateCsrfTokens(except: [
      'api/*',
    ]);

    $middleware->appendToGroup('api', [
      BlockSuspiciousAgents::class,
    ]);

    // 👇 Middlewares de Spatie para roles y permisos
    $middleware->alias([
      'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
      'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
      'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
  })
  ->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (AuthenticationException $e, Request $request) {
      if ($request->is('api/*')) {
        return response()->json([
          'status'  => 'error',
          'message' => 'No autenticado.',
          'data'    => null,
          'errors'  => ['auth' => ['No autenticado.']],
        ], 401);
      }
    });

    $exceptions->render(function (ModelNotFoundException $e, Request $request) {
      if ($request->is('api/*')) {
        return response()->json([
          'status'  => 'error',
          'message' => 'Recurso no encontrado.',
          'data'    => null,
          'errors'  => ['resourse' => 'El registro solicitado no existe.'],
        ], 404);
      }
    });

    $exceptions->render(function (NotFoundHttpException $e, Request $request) {
      if ($request->is('api/*')) {
        return response()->json([
          'status'  => 'error',
          'message' => 'Recurso no encontrado.',
          'data'    => null,
          'errors'  => ['resourse' => 'El registro solicitado no existe.'],
        ], 404);
      }
    });



    $exceptions->render(function (UnauthorizedException $e, Request $request) {
      if ($request->is('api/*')) {
        return response()->json([
          'status'  => 'error',
          'message' => 'No tenés permisos para realizar esta acción.',
          'data'    => null,
          'errors'  => ['auth' => ['No autorizado.']],
        ], 403);
      }
    });
  })->create();
