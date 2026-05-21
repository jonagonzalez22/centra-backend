<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreateFeatureRequest;
use App\Http\Requests\Api\V1\Admin\UpdateFeatureRequest;
use App\Http\Resources\FeatureResource;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FeatureController extends Controller
{
    #[OA\Get(
        path: '/admin/features',
        summary: 'Listar funcionalidades',
        description: 'Retorna la lista paginada de funcionalidades. Permite filtrar por código, nombre y si tienen planes asociados.',
        operationId: 'featureIndex',
        security: [['sanctum' => []]],
        tags: ['Features']
    )]
    #[OA\Parameter(name: 'code', in: 'query', required: false, description: 'Filtrar por código (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'pos'))]
    #[OA\Parameter(name: 'name', in: 'query', required: false, description: 'Filtrar por nombre (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'Punto de venta'))]
    #[OA\Parameter(name: 'has_plans', in: 'query', required: false, description: 'Filtrar por planes asociados (true = con planes, false = sin planes)', schema: new OA\Schema(type: 'boolean', example: false))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de funcionalidades obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Listado de funcionalidades obtenido correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Feature')),
                                new OA\Property(property: 'total', type: 'integer', example: 50),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 4),
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
        $query = Feature::query();

        $query->when($request->filled('code'), function ($q) use ($request) {
            $q->where('code', 'like', '%'.$request->code.'%');
        });

        $query->when($request->filled('name'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->name.'%');
        });

        $query->when($request->filled('has_plans'), function ($q) use ($request) {
            if ($request->boolean('has_plans')) {
                $q->has('plans');
            } else {
                $q->doesntHave('plans');
            }
        });

        $perPage = $request->integer('per_page', 15);
        $features = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Listado de funcionalidades obtenido correctamente.',
            'data' => [
                'items' => FeatureResource::collection($features->items()),
                'total' => $features->total(),
                'per_page' => $features->perPage(),
                'current_page' => $features->currentPage(),
                'last_page' => $features->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/features',
        summary: 'Crear funcionalidad',
        description: 'Crea una nueva funcionalidad en el sistema.',
        operationId: 'featureStore',
        security: [['sanctum' => []]],
        tags: ['Features']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code', 'name'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'pos'),
                new OA\Property(property: 'name', type: 'string', example: 'Punto de venta'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Sistema de punto de venta integrado'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Funcionalidad creada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Feature'),
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
                        new OA\Property(property: 'errors', type: 'object'),
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
    public function store(CreateFeatureRequest $request): JsonResponse
    {
        $feature = Feature::create($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Funcionalidad creada correctamente.',
            'data' => FeatureResource::make($feature),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/admin/features/{id}',
        summary: 'Obtener funcionalidad por ID',
        description: 'Retorna una funcionalidad específica.',
        operationId: 'featureShow',
        security: [['sanctum' => []]],
        tags: ['Features']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID de la funcionalidad',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Funcionalidad obtenida correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Funcionalidad obtenida correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Feature'),
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
    #[OA\Response(
        response: 404,
        description: 'Funcionalidad no encontrada',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Funcionalidad no encontrada.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['feature' => ['No existe una funcionalidad con ese ID']]
                        ),
                    ]
                ),
            ]
        )
    )]
    public function show(string $id): JsonResponse
    {
        $feature = Feature::find($id);

        if (! $feature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Funcionalidad no encontrada.',
                'data' => null,
                'errors' => ['feature' => ['No existe una funcionalidad con ese ID']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Funcionalidad obtenida correctamente.',
            'data' => FeatureResource::make($feature),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/admin/features/{id}',
        summary: 'Actualizar funcionalidad',
        description: 'Actualiza una funcionalidad existente.',
        operationId: 'featureUpdate',
        security: [['sanctum' => []]],
        tags: ['Features']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID de la funcionalidad',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'pos'),
                new OA\Property(property: 'name', type: 'string', example: 'Punto de venta actualizado'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Descripción actualizada'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Funcionalidad actualizada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Funcionalidad actualizada correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Feature'),
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
    #[OA\Response(
        response: 404,
        description: 'Funcionalidad no encontrada',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Funcionalidad no encontrada.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', nullable: true, example: null),
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
    public function update(UpdateFeatureRequest $request, string $id): JsonResponse
    {
        $feature = Feature::find($id);

        if (! $feature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Funcionalidad no encontrada.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $feature->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Funcionalidad actualizada correctamente.',
            'data' => FeatureResource::make($feature),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/admin/features/{id}',
        summary: 'Eliminar funcionalidad',
        description: 'Elimina una funcionalidad por ID. No se puede eliminar si tiene planes asociados.',
        operationId: 'featureDestroy',
        security: [['sanctum' => []]],
        tags: ['Features']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID de la funcionalidad a eliminar',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Funcionalidad eliminada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Funcionalidad eliminada correctamente.'),
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
        description: 'Funcionalidad no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflicto - tiene planes asociados',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No se puede eliminar la funcionalidad porque tiene planes asociados.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function destroy(string $id): JsonResponse
    {
        $feature = Feature::find($id);

        if (! $feature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Funcionalidad no encontrada.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        if ($feature->plans()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la funcionalidad porque tiene planes asociados.',
                'data' => null,
                'errors' => null,
            ], 409);
        }

        $feature->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Funcionalidad eliminada correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
