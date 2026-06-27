<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Support\PermissionFeatureResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionCatalogController extends Controller
{
    /**
     * Display the permission catalog available for the store.
     *
     * @OA\Get(
     *   path="/store/permissions/catalog",
     *   summary="Catálogo de permisos disponibles para la tienda",
     *   tags={"Store - Usuarios"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Catálogo de permisos obtenido correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Catálogo de permisos obtenido correctamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="groups", type="object",
     *
     *           @OA\AdditionalProperties(
     *             type="array",
     *
     *             @OA\Items(
     *
     *               @OA\Property(property="name", type="string", example="products.view"),
     *               @OA\Property(property="label", type="string", example="Ver")
     *             )
     *           )
     *         )
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No tenés permiso para acceder a este recurso")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermissionTo('store_users.view') && ! $user->hasPermissionTo('store_users.edit')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tenés permiso para acceder a este recurso.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        $store = $user->store->load('plan.features');

        $catalog = config('permissions_catalog', []);
        $groups = [];

        foreach ($catalog as $groupName => $permissions) {
            $filtered = [];

            foreach ($permissions as $permission) {
                $featureCode = PermissionFeatureResolver::resolveFeature($permission['name']);

                if ($featureCode === null) {
                    continue;
                }

                if (! $store->hasFeature($featureCode)) {
                    continue;
                }

                $filtered[] = $permission;
            }

            if (! empty($filtered)) {
                $groups[$groupName] = $filtered;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Catálogo de permisos obtenido correctamente.',
            'data' => [
                'groups' => $groups,
            ],
            'errors' => null,
        ]);
    }
}
