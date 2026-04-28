<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
  public function handle(Request $request, Closure $next, string $featureCode): Response
  {
    $user = $request->user();

    // Si es usuario del backoffice (sin store), dejamos pasar siempre
    if (is_null($user->store_id)) {
      return $next($request);
    }

    // Si es usuario de una tienda, validamos el plan
    $store = $user->store;

    if (!$store || !$store->plan) {
      return response()->json([
        'status'  => 'error',
        'message' => 'No tenés un plan activo asignado.',
        'data'    => null,
        'errors'  => ['plan' => ['La tienda no tiene un plan asignado.']],
      ], 403);
    }

    if (!$store->hasFeature($featureCode)) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Tu plan actual no incluye esta funcionalidad.',
        'data'    => null,
        'errors'  => ['feature' => ["El plan no incluye la funcionalidad: {$featureCode}"]],
      ], 403);
    }

    return $next($request);
  }
}
