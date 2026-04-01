<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
  /**
   * POST /api/v1/login
   */
  public function login(LoginRequest $request): JsonResponse
  {
    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
      return response()->json([
        'data'    => null,
        'message' => 'Credenciales inválidas.',
        'errors'  => ['email' => ['El email o la contraseña son incorrectos.']],
      ], 401);
    }

    $user->tokens()->delete();

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
      'data' => [
        'token' => $token,
        'user'  => $this->formatUser($user),
      ],
      'message' => 'Autenticación exitosa.',
      'errors'  => null,
    ], 200);
  }

  /**
   * POST /api/v1/logout
   */
  public function logout(Request $request): JsonResponse
  {
    $request->user()->tokens()->delete();

    Auth::guard('web')->logout();

    if ($request->hasSession()) {
      $request->session()->invalidate();
      $request->session()->regenerateToken();
    }

    return response()->json([
      'data'    => null,
      'message' => 'Sesión cerrada correctamente.',
      'errors'  => null,
    ], 200);
  }

  /**
   * GET /api/v1/me
   */
  public function me(Request $request): JsonResponse
  {
    $user = $request->user()->load('roles');

    return response()->json([
      'data' => [
        'user' => $this->formatUser($user),
      ],
      'message' => 'Usuario autenticado.',
      'errors'  => null,
    ], 200);
  }

  /**
   * Format User for standar response.
   */
  private function formatUser(User $user): array
  {
    return [
      'id'          => $user->id,
      'name'        => $user->name,
      'email'       => $user->email,
      'store_id'    => $user->store_id,
      'roles'       => $user->getRoleNames()->toArray(),
      'permissions' => $user->getPermissionsViaRoles()
        ->pluck('name')
        ->toArray(),
    ];
  }
}
