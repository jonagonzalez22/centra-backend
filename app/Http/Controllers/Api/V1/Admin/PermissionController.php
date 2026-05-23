<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    #[OA\Get(
        path: '/admin/permissions',
        summary: 'Listar permisos',
        description: 'Retorna todos los permisos disponibles en el sistema, agrupados por recurso.',
        operationId: 'permissionIndex',
        security: [['sanctum' => []]],
        tags: ['Permissions']
    )]
    #[OA\Response(
        response: 200,
        description: 'Listado de permisos obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Listado de permisos obtenido correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            example: ['stores' => ['stores.view', 'stores.create'], 'users' => ['users.view']]
                        ),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No autenticado.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object', example: ['auth' => ['Token inválido o ausente']]),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 403,
        description: 'Sin permisos',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No tenés permisos para realizar esta acción.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    public function index(): JsonResponse
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            $parts = explode('.', $permission->name);

            return $parts[0];
        })->map(function ($group) {
            return $group->pluck('name')->values();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Listado de permisos obtenido correctamente.',
            'data' => $permissions,
            'errors' => null,
        ]);
    }
}
