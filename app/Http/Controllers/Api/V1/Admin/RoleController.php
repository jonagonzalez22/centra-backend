<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreateRoleRequest;
use App\Http\Requests\Api\V1\Admin\SyncPermissionsRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    #[OA\Get(
        path: '/admin/roles',
        summary: 'Listar roles',
        description: 'Retorna la lista paginada de roles con conteo de usuarios y permisos.',
        operationId: 'roleIndex',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de roles obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Listado de roles obtenido correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Role')),
                                new OA\Property(property: 'total', type: 'integer', example: 5),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                            ]
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
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        $roles = Role::withCount('permissions')
            ->with('permissions')
            ->paginate($perPage);

        $roleIds = $roles->pluck('id');
        $usersCounts = DB::table($modelHasRolesTable)
            ->whereIn('role_id', $roleIds)
            ->selectRaw('role_id, count(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        $items = $roles->items();
        foreach ($items as $role) {
            $role->users_count = $usersCounts->get($role->id, 0);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Listado de roles obtenido correctamente.',
            'data' => [
                'items' => RoleResource::collection($items),
                'total' => $roles->total(),
                'per_page' => $roles->perPage(),
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/roles',
        summary: 'Crear rol',
        description: 'Crea un nuevo rol en el sistema.',
        operationId: 'roleStore',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'editor'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Rol creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Rol creado correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
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
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Error de validación.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->name,
            'guard_name' => 'web',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Rol creado correctamente.',
            'data' => RoleResource::make($role),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/admin/roles/{id}',
        summary: 'Obtener rol por ID',
        description: 'Retorna un rol específico con sus permisos asociados.',
        operationId: 'roleShow',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del rol',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Rol obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Rol obtenido correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
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
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Rol no encontrado',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Rol no encontrado.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['role' => ['No existe un rol con ese ID']]
                        ),
                    ]
                ),
            ]
        )
    )]
    public function show(string $id): JsonResponse
    {
        $role = Role::with('permissions')->find($id);

        if (! $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rol no encontrado.',
                'data' => null,
                'errors' => ['role' => ['No existe un rol con ese ID']],
            ], 404);
        }

        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $role->users_count = DB::table($modelHasRolesTable)
            ->where('role_id', $role->id)
            ->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Rol obtenido correctamente.',
            'data' => RoleResource::make($role),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/admin/roles/{id}',
        summary: 'Actualizar rol',
        description: 'Actualiza un rol existente.',
        operationId: 'roleUpdate',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del rol',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'editor'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Rol actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Rol actualizado correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
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
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Rol no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Error de validación.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function update(UpdateRoleRequest $request, string $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rol no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $role->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Rol actualizado correctamente.',
            'data' => RoleResource::make($role->load('permissions')),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/admin/roles/{id}',
        summary: 'Eliminar rol',
        description: 'Elimina un rol por ID. No se puede eliminar si tiene usuarios asignados o si es SUPER_ADMIN.',
        operationId: 'roleDestroy',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del rol a eliminar',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Rol eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Rol eliminado correctamente.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
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
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Rol no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflicto - tiene usuarios asignados',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No se puede eliminar el rol porque tiene usuarios asignados.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'No se puede eliminar el rol protegido',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No se puede eliminar el rol SUPER_ADMIN.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function destroy(string $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rol no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        if ($role->name === 'SUPER_ADMIN') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el rol SUPER_ADMIN.',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el rol porque tiene usuarios asignados.',
                'data' => null,
                'errors' => null,
            ], 409);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Rol eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/roles/{id}/sync-permissions',
        summary: 'Sincronizar permisos del rol',
        description: 'Sincroniza los permisos asociados a un rol, reemplazando los existentes. Puede enviarse un array vacío para quitar todos los permisos. Crea permisos faltantes automáticamente.',
        operationId: 'roleSyncPermissions',
        security: [['sanctum' => []]],
        tags: ['Roles']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del rol',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['permissions'],
            properties: [
                new OA\Property(
                    property: 'permissions',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: []
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Permisos sincronizados correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Permisos sincronizados correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Role'),
                        new OA\Property(property: 'errors', nullable: true, example: null),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'No autenticado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
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
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Rol no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Error de validación.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function syncPermissions(SyncPermissionsRequest $request, string $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rol no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        foreach ($request->permissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web']
            );
        }

        $role->syncPermissions($request->permissions);

        return response()->json([
            'status' => 'success',
            'message' => 'Permisos sincronizados correctamente.',
            'data' => RoleResource::make($role->load('permissions')),
            'errors' => null,
        ]);
    }
}
