<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
  public function index(): JsonResponse
  {
    // TICKET 19
    return response()->json(['message' => 'Index - Ticket 19']);
  }

  #[OA\Post(
    path: "/admin/users",
    summary: "Crear usuario",
    description: "Crea un nuevo usuario. El STORE_ADMIN solo puede crear usuarios dentro de su tienda. El SUPER_ADMIN puede crear usuarios en cualquier tienda.",
    operationId: "userStore",
    security: [["sanctum" => []]],
    tags: ["Users"]
  )]

  #[OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
      required: ["name", "email", "password", "password_confirmation", "role"],
      properties: [
        new OA\Property(property: "name", type: "string", example: "Juan Pérez"),
        new OA\Property(property: "email", type: "string", example: "juan@centra.com"),
        new OA\Property(property: "password", type: "string", example: "Password1"),
        new OA\Property(property: "password_confirmation", type: "string", example: "Password1"),
        new OA\Property(property: "role", type: "string", example: "STORE_ADMIN"),
        new OA\Property(property: "store_id", type: "string", nullable: true, example: "019dd4bc-7318-7094-829b-a02485ba6caf"),
      ]
    )
  )]

  #[OA\Response(
    response: 201,
    description: "Usuario creado correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "data", ref: "#/components/schemas/User"),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 401,
    description: "No autenticado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No autenticado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object")
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 422,
    description: "Error de validación",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Error de validación."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", type: "object")
          ]
        )
      ]
    )
  )]

  #[OA\Response(
    response: 500,
    description: "Error interno del servidor",
    content: new OA\JsonContent(ref: "#/components/schemas/ApiResponse")
  )]
  public function store(CreateUserRequest $request): JsonResponse
  {
    $authUser = $request->user();

    // 🔐 Lógica multi-tenant
    // STORE_ADMIN → siempre su propio store_id (ignoramos lo que mande)
    // SUPER_ADMIN → usa el store_id del request (puede ser null)
    $storeId = $authUser->hasRole('SUPER_ADMIN')
      ? $request->store_id
      : $authUser->store_id;

    try {
      return DB::transaction(function () use ($request, $storeId) {

        $user = User::create([
          'name'     => $request->name,
          'email'    => $request->email,
          'password' => $request->password,
          'store_id' => $storeId,
        ]);


        $user->assignRole($request->role);

        // 🔥 Cargamos relaciones para el Resource
        $user->load(['store.plan.features', 'roles']);

        return response()->json([
          'status'  => 'success',
          'message' => 'Usuario creado correctamente.',
          'data'    => UserResource::make($user),
          'errors'  => null,
        ], 201);
      });
    } catch (\Exception $e) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Error al crear el usuario.',
        'data'    => null,
        'errors'  => $e->getMessage(),
      ], 500);
    }
  }

  public function show(string $id): JsonResponse
  {
    // TICKET 19
    return response()->json(['message' => 'Show - Ticket 19']);
  }

  public function update(Request $request, string $id): JsonResponse
  {
    // TICKET 20
    return response()->json(['message' => 'Update - Ticket 20']);
  }

  public function destroy(string $id): JsonResponse
  {
    // TICKET 21
    return response()->json(['message' => 'Destroy - Ticket 21']);
  }
}
