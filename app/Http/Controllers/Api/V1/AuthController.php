<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{


  #[OA\Post(
    path: "/login",
    summary: "Inicio de sesión",
    description: "Autentica al usuario y devuelve un token de acceso (Sanctum).",
    operationId: "authLogin",
    tags: ["Autenticación"]
  )]
  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      required: ["email", "password"],
      properties: [
        new OA\Property(property: "email", type: "string", format: "email", example: "admin@centra.com"),
        new OA\Property(property: "password", type: "string", format: "password", example: "password123")
      ]
    )
  )]
  #[OA\Response(
    response: 200,
    description: "Login exitoso",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              type: "object",
              properties: [
                new OA\Property(property: "token", type: "string", example: "1|abc123token..."),
                new OA\Property(property: "user", ref: "#/components/schemas/User"),
              ]
            ),
            new OA\Property(property: "errors", type: "string", nullable: true, example: null),
          ]
        ),
      ]
    )
  )]
  #[OA\Response(
    response: 401,
    description: "Credenciales incorrectas",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", type: "string", example: "error"),
            new OA\Property(property: "message", type: "string", example: "Credenciales incorrectas"),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "auth" => [
                  "El email o la contraseña no coinciden con nuestros registros."
                ]
              ]
            )
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 422,
    description: "Error de validación de campos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", type: "string", example: "error"),
            new OA\Property(property: "message", type: "string", example: "Datos de entrada inválidos"),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "email" => ["The email field must be a valid email address."],
                "password" => ["The password field is required."]
              ]
            )
          ]
        )
      ]
    )
  )]
  public function login(LoginRequest $request): JsonResponse
  {
    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Credenciales inválidas.',
        'data'    => null,
        'errors'  => ['auth' => ['El email o la contraseña son incorrectos.']],
      ], 401);
    }

    $user->tokens()->delete();

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
      'status' => 'success',
      'message' => 'Autenticación exitosa.',
      'data' => [
        'token' => $token,
        'user'  => $this->formatUser($user),
      ],
      'errors'  => null,
    ], 200);
  }

  /**
   * POST /api/v1/logout
   */


  #[OA\Post(
    path: "/logout",
    summary: "Cerrar sesión",
    description: "Revoca todos los tokens del usuario autenticado.",
    operationId: "authLogout",
    security: [['sanctum' => []]],
    tags: ["Autenticación"]
  )]
  #[OA\Response(
    response: 200,
    description: "Sesión cerrada correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null)
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 401,
    description: "No autenticado (token inválido o ausente)",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", type: "string", example: "error"),
            new OA\Property(property: "message", type: "string", example: "Unauthenticated."),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "auth" => [
                  "No autenticado."
                ]
              ]
            )
          ]
        )
      ]
    )
  )]


  public function logout(Request $request): JsonResponse
  {
    $request->user()->tokens()->delete();

    Auth::guard('web')->logout();

    if ($request->hasSession()) {
      $request->session()->invalidate();
      $request->session()->regenerateToken();
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Sesión cerrada correctamente.',
      'data'    => null,
      'errors'  => null,
    ], 200);
  }

  /**
   * GET /api/v1/me
   */

  #[OA\Get(
    path: "/me",
    summary: "Obtener usuario autenticado",
    description: "Retorna los datos del usuario que tiene la sesión activa.",
    operationId: "authMe",
    security: [['sanctum' => []]],
    tags: ["Autenticación"]
  )]
  #[OA\Response(
    response: 200,
    description: "Usuario obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(
              property: "data",
              type: "object",
              properties: [
                new OA\Property(property: "user", ref: "#/components/schemas/User")
              ]
            ),
            new OA\Property(property: "errors", nullable: true, example: null),
            new OA\Property(property: "message", example: 'Usuario autenticado.')
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 401,
    description: "No autenticado (token inválido o ausente)",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", type: "string", example: "error"),
            new OA\Property(property: "message", type: "string", example: "No autenticado."),
            new OA\Property(
              property: "errors",
              type: "object",
              example: [
                "auth" => [
                  "No autenticado."
                ]
              ]
            )
          ]
        )
      ]
    )
  )]

  public function me(Request $request): JsonResponse
  {
    $user = $request->user()->load('roles');

    return response()->json([
      'status' => 'success',
      'message' => 'Usuario autenticado.',
      'data' => [
        'user' => $this->formatUser($user),
      ],
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
