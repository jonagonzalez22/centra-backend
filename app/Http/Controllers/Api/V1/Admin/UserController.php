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
  #[OA\Get(
    path: "/admin/users",
    summary: "Listar usuarios",
    description: "Retorna la lista paginada de usuarios. STORE_ADMIN solo ve los de su tienda. SUPER_ADMIN ve todos.",
    operationId: "userIndex",
    security: [["sanctum" => []]],
    tags: ["Users"]
  )]
  #[OA\Parameter(name: "name", in: "query", required: false, description: "Filtrar por nombre", schema: new OA\Schema(type: "string", example: "Juan"))]
  #[OA\Parameter(name: "role", in: "query", required: false, description: "Filtrar por nombre de rol", schema: new OA\Schema(type: "string", example: "ADMIN"))]
  #[OA\Parameter(name: "store_id", in: "query", required: false, description: "Filtrar por store_id (solo SUPER_ADMIN)", schema: new OA\Schema(type: "string", example: "019dd4bc-7318-7094-829b-a02485ba6caf"))]
  #[OA\Parameter(name: "per_page", in: "query", required: false, description: "Resultados por página (default: 15)", schema: new OA\Schema(type: "integer", example: 15))]
  #[OA\Parameter(name: "page", in: "query", required: false, description: "Número de página", schema: new OA\Schema(type: "integer", example: 1))]

  #[OA\Response(
    response: 200,
    description: "Listado de usuarios obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Listado de usuarios obtenido correctamente."),
            new OA\Property(
              property: "data",
              type: "object",
              properties: [
                new OA\Property(property: "items", type: "array", items: new OA\Items(ref: "#/components/schemas/User")),
                new OA\Property(property: "total", type: "integer", example: 50),
                new OA\Property(property: "per_page", type: "integer", example: 15),
                new OA\Property(property: "current_page", type: "integer", example: 1),
                new OA\Property(property: "last_page", type: "integer", example: 4),
              ]
            ),
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
            new OA\Property(property: "errors", type: "object", example: ["auth" => ["Token inválido o ausente"]])
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  public function index(Request $request): JsonResponse
  {
    $authUser = $request->user();

    $query = User::with(['roles', 'store.plan.features']);

    // 1. Multi-tenant security filter
    if ($authUser->hasRole('STORE_ADMIN')) {
      $query->where('store_id', $authUser->store_id);
    }

    $query->when($request->filled('name'), function ($q) use ($request) {
      $q->where('name', 'like', '%' . $request->name . '%');
    });

    $query->when($request->filled('role'), function ($q) use ($request) {
      $q->role($request->role);
    });

    // Only SUPER_ADMIN can filter by any store_id
    $query->when($authUser->hasRole('SUPER_ADMIN') && $request->filled('store_id'), function ($q) use ($request) {
      $q->where('store_id', $request->store_id);
    });

    $perPage = $request->integer('per_page', 15);
    $users = $query->paginate($perPage);

    return response()->json([
      'status'  => 'success',
      'message' => 'Listado de usuarios obtenido correctamente.',
      'data'    => [
        'items'        => UserResource::collection($users->items()),
        'total'        => $users->total(),
        'per_page'     => $users->perPage(),
        'current_page' => $users->currentPage(),
        'last_page'    => $users->lastPage(),
      ],
      'errors'  => null,
    ]);
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


    if ($authUser->hasRole('STORE_ADMIN') && $request->role === 'SUPER_ADMIN') {
      return response()->json([
        'status' => 'error',
        'message' => 'No tenés permisos para asignar ese rol.',
        'data' => null,
        'errors' => [
          'role' => ['No podés asignar el rol SUPER_ADMIN.']
        ],
      ], 403);
    }

    try {
      return DB::transaction(function () use ($request, $storeId) {

        $user = User::create([
          'name'     => $request->name,
          'email'    => $request->email,
          'password' => $request->password,
          'store_id' => $storeId,
        ]);


        $user->assignRole($request->role);

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
  #[OA\Get(
    path: "/admin/users/{id}",
    summary: "Obtener un usuario",
    description: "Retorna los datos de un usuario específico respetando el ámbito de la tienda.",
    operationId: "userShow",
    security: [["sanctum" => []]],
    tags: ["Users"]
  )]
  #[OA\Parameter(
    name: "id",
    in: "path",
    required: true,
    description: "ID del usuario (UUID)",
    schema: new OA\Schema(type: "string", example: "019dd4bc-7318-7094-829b-a02485ba6caf")
  )]
  #[OA\Response(
    response: 200,
    description: "Usuario obtenido correctamente",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "success"),
            new OA\Property(property: "message", example: "Usuario obtenido correctamente."),
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
            new OA\Property(property: "errors", type: "object", example: ["auth" => ["Token inválido o ausente"]])
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 403,
    description: "Sin permisos",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "No tenés permisos para realizar esta acción."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(property: "errors", nullable: true, example: null),
          ]
        )
      ]
    )
  )]
  #[OA\Response(
    response: 404,
    description: "Usuario no encontrado",
    content: new OA\JsonContent(
      allOf: [
        new OA\Schema(ref: "#/components/schemas/ApiResponse"),
        new OA\Schema(
          properties: [
            new OA\Property(property: "status", example: "error"),
            new OA\Property(property: "message", example: "Usuario no encontrado."),
            new OA\Property(property: "data", nullable: true, example: null),
            new OA\Property(
              property: "errors",
              type: "object",
              example: ["user" => ["El usuario no existe o no tiene permisos para verlo."]]
            ),
          ]
        )
      ]
    )
  )]
  public function show(Request $request, string $id): JsonResponse
  {
    /** @var \App\Models\User $authUser */
    $authUser = $request->user();

    $query = User::with(['roles', 'store.plan.features'])->where('id', $id);

    // 🔐 Security: STORE_ADMIN can only view users belonging to their store
    if ($authUser->hasRole('STORE_ADMIN')) {
      $query->where('store_id', $authUser->store_id);
    }

    $user = $query->first();

    if (!$user) {
      return response()->json([
        'status'  => 'error',
        'message' => 'Usuario no encontrado.',
        'data'    => null,
        'errors'  => ['user' => ['El usuario no existe o no tiene permisos para verlo.']],
      ], 404);
    }

    return response()->json([
      'status'  => 'success',
      'message' => 'Usuario obtenido correctamente.',
      'data'    => UserResource::make($user),
      'errors'  => null,
    ]);
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
