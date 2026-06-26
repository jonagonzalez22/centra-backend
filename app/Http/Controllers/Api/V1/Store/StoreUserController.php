<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreUserStoreRequest;
use App\Http\Requests\Api\V1\Store\StoreUserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class StoreUserController extends Controller
{
    private const ADMIN_ROLES = ['SUPER_ADMIN', 'STORE_ADMIN'];

    /**
     * Filter options for the users list.
     *
     * @OA\Get(
     *   path="/store/users/filter-options",
     *   summary="Opciones de filtro para usuarios de la tienda",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Opciones de filtro obtenidas correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Opciones de filtro obtenidas correctamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="roles", type="array", @OA\Items(type="object",
     *           @OA\Property(property="id", type="integer", example=1),
     *           @OA\Property(property="name", type="string", example="STORE_USER")
     *         ))
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $roles = Role::select('id', 'name')
            ->whereNotIn('name', self::ADMIN_ROLES)
            ->where('name', 'NOT LIKE', 'BACKOFFICE%')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Opciones de filtro obtenidas correctamente.',
            'data' => [
                'roles' => $roles,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Display a listing of users for the authenticated user's store.
     *
     * @OA\Get(
     *   path="/store/users",
     *   summary="Listar usuarios de la tienda",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="name", in="query", @OA\Schema(type="string"), description="Filtrar por nombre (búsqueda parcial)"),
     *   @OA\Parameter(name="email", in="query", @OA\Schema(type="string"), description="Filtrar por email (búsqueda parcial)"),
     *   @OA\Parameter(name="role", in="query", @OA\Schema(type="string"), description="Filtrar por nombre de rol"),
     *   @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean"), description="Filtrar por estado activo"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Usuarios obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Usuarios obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/User")),
     *         @OA\Property(property="total", type="integer", example=4),
     *         @OA\Property(property="per_page", type="integer", example=15),
     *         @OA\Property(property="current_page", type="integer", example=1),
     *         @OA\Property(property="last_page", type="integer", example=1)
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="Funcionalidad no incluida en el plan")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $query = User::with(['roles', 'store.plan.features'])
            ->where('store_id', $storeId);

        $query->when($request->filled('name'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->name.'%');
        });

        $query->when($request->filled('email'), function ($q) use ($request) {
            $q->where('email', 'like', '%'.$request->email.'%');
        });

        $query->when($request->filled('role'), function ($q) use ($request) {
            $q->role($request->role);
        });

        $query->when($request->has('is_active'), function ($q) use ($request) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        $users = $query->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Usuarios obtenidos exitosamente.',
            'data' => [
                'items' => UserResource::collection($users->items()),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a newly created user for the authenticated user's store.
     *
     * @OA\Post(
     *   path="/store/users",
     *   summary="Crear un nuevo usuario",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"name", "email", "password", "password_confirmation", "role"},
     *
     *       @OA\Property(property="name", type="string", maxLength=255, example="Juan Pérez"),
     *       @OA\Property(property="email", type="string", maxLength=255, example="juan@tienda.com"),
     *       @OA\Property(property="password", type="string", example="Password1"),
     *       @OA\Property(property="password_confirmation", type="string", example="Password1"),
     *       @OA\Property(property="role", type="string", example="STORE_USER"),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Usuario creado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Usuario creado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/User"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="Límite de usuarios alcanzado o rol no permitido"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function store(StoreUserStoreRequest $request): JsonResponse
    {
        $authUser = $request->user();
        $storeId = $authUser->store_id;

        $store = $authUser->store->load('plan.features');
        $currentUsers = $store->users()->count();

        if (! $store->canUseFeature('multi_user', $currentUsers)) {
            $limit = $store->getFeatureLimit('multi_user');

            return response()->json([
                'status' => 'error',
                'message' => 'Has alcanzado el límite de usuarios de tu plan.',
                'data' => null,
                'errors' => [
                    'limit' => ["Tu plan permite un máximo de {$limit} usuarios."],
                ],
            ], 403);
        }

        if (! $this->canAssignRole($request->role)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permisos para asignar ese rol.',
                'data' => null,
                'errors' => ['role' => ['No podés asignar roles administrativos.']],
            ], 403);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'store_id' => $storeId,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $user->assignRole($request->role);
        $user->load(['roles', 'store.plan.features']);

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario creado exitosamente.',
            'data' => UserResource::make($user),
            'errors' => null,
        ], 201);
    }

    /**
     * Display the specified user.
     *
     * @OA\Get(
     *   path="/store/users/{id}",
     *   summary="Ver un usuario específico",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Usuario obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Usuario obtenido exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/User"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Usuario no encontrado")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $user = User::with(['roles', 'store.plan.features'])
            ->where('store_id', $storeId)
            ->find($id);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El usuario no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario obtenido exitosamente.',
            'data' => UserResource::make($user),
            'errors' => null,
        ], 200);
    }

    /**
     * Update the specified user.
     *
     * @OA\Put(
     *   path="/store/users/{id}",
     *   summary="Actualizar un usuario",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="name", type="string", maxLength=255, example="Juan Modificado", nullable=true),
     *       @OA\Property(property="email", type="string", maxLength=255, example="juan_nuevo@tienda.com", nullable=true),
     *       @OA\Property(property="password", type="string", example="NewPassword123", nullable=true),
     *       @OA\Property(property="password_confirmation", type="string", example="NewPassword123", nullable=true),
     *       @OA\Property(property="role", type="string", example="STORE_USER", nullable=true),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Usuario actualizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Usuario actualizado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/User"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No podés modificar tu propio rol o asignar roles administrativos"),
     *   @OA\Response(response=404, description="Usuario no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function update(StoreUserUpdateRequest $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        $storeId = $authUser->store_id;

        $user = User::with(['roles', 'store.plan.features'])
            ->where('store_id', $storeId)
            ->find($id);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El usuario no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        if ($authUser->id === $user->id && $request->filled('role')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No podés modificar tu propio rol.',
                'data' => null,
                'errors' => ['role' => ['No podés cambiar tu propio rol.']],
            ], 403);
        }

        if ($authUser->id === $user->id && $request->has('is_active') && ! $request->boolean('is_active')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No podés desactivar tu propio usuario.',
                'data' => null,
                'errors' => ['is_active' => ['No podés desactivar tu propio usuario.']],
            ], 403);
        }

        if ($request->filled('role') && ! $this->canAssignRole($request->role)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permisos para asignar ese rol.',
                'data' => null,
                'errors' => ['role' => ['No podés asignar roles administrativos.']],
            ], 403);
        }

        $user->fill($request->only(['name', 'email']));

        if ($request->filled('password')) {
            $user->password = $request->password;
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->boolean('is_active');
        }

        $user->save();

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        }

        $user->load(['roles', 'store.plan.features']);

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario actualizado exitosamente.',
            'data' => UserResource::make($user),
            'errors' => null,
        ], 200);
    }

    /**
     * Remove the specified user.
     *
     * @OA\Delete(
     *   path="/store/users/{id}",
     *   summary="Eliminar un usuario",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Usuario eliminado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Usuario eliminado exitosamente."),
     *       @OA\Property(property="data", type="null", example=null),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No podés eliminarte a vos mismo"),
     *   @OA\Response(response=404, description="Usuario no encontrado")
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();

        if ($authUser->id === $id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No podés eliminar tu propio usuario.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        $storeId = $authUser->store_id;

        $user = User::where('store_id', $storeId)->find($id);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El usuario no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario eliminado exitosamente.',
            'data' => null,
            'errors' => null,
        ], 200);
    }

    /**
     * Check if the authenticated user can assign the given role.
     * STORE_ADMIN cannot assign administrative roles (SUPER_ADMIN, STORE_ADMIN).
     */
    private function canAssignRole(string $role): bool
    {
        return ! in_array($role, self::ADMIN_ROLES, true);
    }
}
