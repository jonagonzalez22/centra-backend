<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCommercialGroupRequest;
use App\Http\Requests\Api\V1\Store\UpdateCommercialGroupRequest;
use App\Http\Resources\CommercialGroupResource;
use App\Models\CommercialGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CommercialGroupController extends Controller
{
    #[OA\Get(
        path: '/store/commercial-groups',
        summary: 'Listar grupos comerciales',
        description: 'Retorna la lista paginada de grupos comerciales de la tienda del usuario autenticado.',
        operationId: 'commercialGroupIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Grupos Comerciales']
    )]
    #[OA\Parameter(name: 'name', in: 'query', required: false, description: 'Filtrar por nombre (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'VIP'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de grupos comerciales obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Grupos comerciales obtenidos exitosamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommercialGroup')),
                                new OA\Property(property: 'total', type: 'integer', example: 10),
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
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 403,
        description: 'Plan no incluye la funcionalidad',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $groups = CommercialGroup::forStore($storeId)
            ->when($request->filled('name'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->name . '%');
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Grupos comerciales obtenidos exitosamente.',
            'data' => [
                'items' => CommercialGroupResource::collection($groups->items()),
                'total' => $groups->total(),
                'per_page' => $groups->perPage(),
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/commercial-groups',
        summary: 'Crear grupo comercial',
        description: 'Crea un nuevo grupo comercial en la tienda del usuario autenticado.',
        operationId: 'commercialGroupStore',
        security: [['sanctum' => []]],
        tags: ['Store - Grupos Comerciales']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Clientes VIP'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Grupo con descuentos especiales'),
                new OA\Property(property: 'settings', type: 'object', nullable: true, example: '{"discount_percent": 10}'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Grupo comercial creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CommercialGroup'),
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
        description: 'Plan no incluye la funcionalidad',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function store(StoreCommercialGroupRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $group = CommercialGroup::create([
            'store_id' => $storeId,
            'name' => $request->name,
            'description' => $request->description,
            'settings' => $request->settings,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Grupo comercial creado correctamente.',
            'data' => CommercialGroupResource::make($group),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/store/commercial-groups/{id}',
        summary: 'Obtener grupo comercial por ID',
        description: 'Retorna un grupo comercial específico de la tienda del usuario autenticado.',
        operationId: 'commercialGroupShow',
        security: [['sanctum' => []]],
        tags: ['Store - Grupos Comerciales']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del grupo comercial',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\Response(
        response: 200,
        description: 'Grupo comercial obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Grupo comercial obtenido exitosamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CommercialGroup'),
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
        description: 'Plan no incluye la funcionalidad',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Grupo comercial no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $group = CommercialGroup::forStore($storeId)->find($id);

        if (! $group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Grupo comercial no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El grupo comercial no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Grupo comercial obtenido exitosamente.',
            'data' => CommercialGroupResource::make($group),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/store/commercial-groups/{id}',
        summary: 'Actualizar grupo comercial',
        description: 'Actualiza un grupo comercial existente de la tienda del usuario autenticado.',
        operationId: 'commercialGroupUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Grupos Comerciales']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del grupo comercial',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Clientes Premium'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Descripción actualizada'),
                new OA\Property(property: 'settings', type: 'object', nullable: true, example: '{"discount_percent": 15}'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Grupo comercial actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CommercialGroup'),
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
        description: 'Plan no incluye la funcionalidad',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Grupo comercial no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function update(UpdateCommercialGroupRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $group = CommercialGroup::forStore($storeId)->find($id);

        if (! $group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Grupo comercial no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El grupo comercial no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $group->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Grupo comercial actualizado correctamente.',
            'data' => CommercialGroupResource::make($group),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/store/commercial-groups/{id}',
        summary: 'Eliminar grupo comercial',
        description: 'Elimina un grupo comercial por ID.',
        operationId: 'commercialGroupDestroy',
        security: [['sanctum' => []]],
        tags: ['Store - Grupos Comerciales']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del grupo comercial a eliminar',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\Response(
        response: 200,
        description: 'Grupo comercial eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Grupo comercial eliminado correctamente.'),
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
        description: 'Plan no incluye la funcionalidad',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 404,
        description: 'Grupo comercial no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $group = CommercialGroup::forStore($storeId)->find($id);

        if (! $group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Grupo comercial no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El grupo comercial no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $group->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Grupo comercial eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
