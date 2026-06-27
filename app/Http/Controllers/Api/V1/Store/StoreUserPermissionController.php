<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreUserPermissionRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreUserPermissionController extends Controller
{
    /**
     * Display the direct permissions of a user.
     *
     * @OA\Get(
     *   path="/store/users/{id}/permissions",
     *   summary="Ver permisos directos de un usuario",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Permisos obtenidos correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Permisos obtenidos correctamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="permissions", type="array", @OA\Items(type="string", example="products.view"))
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No tenés permiso para realizar esta acción"),
     *   @OA\Response(response=404, description="Usuario no encontrado")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('store_users.edit');

        $authUser = $request->user();
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

        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        return response()->json([
            'status' => 'success',
            'message' => 'Permisos obtenidos correctamente.',
            'data' => [
                'permissions' => $permissions,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Sync direct permissions of a user.
     *
     * @OA\Post(
     *   path="/store/users/{id}/permissions",
     *   summary="Sincronizar permisos directos de un usuario",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"permissions"},
     *
     *       @OA\Property(property="permissions", type="array", @OA\Items(type="string", example="products.view"),
     *         description="Lista de nombres de permisos directos a asignar")
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Permisos sincronizados correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Permisos sincronizados correctamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/User"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No tenés permiso para modificar este usuario o es un SUPER_ADMIN"),
     *   @OA\Response(response=404, description="Usuario no encontrado"),
     *   @OA\Response(response=422, description="Uno o más permisos no son válidos o no están disponibles en tu plan")
     * )
     */
    public function update(StoreUserPermissionRequest $request, string $id): JsonResponse
    {
        Gate::authorize('store_users.edit');

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

        if ($authUser->id === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No podés modificar tus propios permisos.',
                'data' => null,
                'errors' => ['id' => ['No podés modificar tus propios permisos.']],
            ], 403);
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No podés modificar los permisos de un SUPER_ADMIN.',
                'data' => null,
                'errors' => ['id' => ['No podés modificar los permisos de un SUPER_ADMIN.']],
            ], 403);
        }

        $user->syncPermissions($request->permissions);
        $user->load(['roles', 'store.plan.features']);

        return response()->json([
            'status' => 'success',
            'message' => 'Permisos sincronizados correctamente.',
            'data' => UserResource::make($user),
            'errors' => null,
        ]);
    }
}
