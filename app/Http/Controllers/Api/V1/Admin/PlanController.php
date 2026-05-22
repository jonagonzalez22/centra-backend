<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreatePlanRequest;
use App\Http\Requests\Api\V1\Admin\SyncFeaturesRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    #[OA\Get(
        path: '/admin/plans',
        summary: 'Listar planes',
        description: 'Retorna la lista paginada de planes. Permite filtrar por nombre, ciclo de facturación y estado.',
        operationId: 'planIndex',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\Parameter(name: 'name', in: 'query', required: false, description: 'Filtrar por nombre (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'Básico'))]
    #[OA\Parameter(name: 'billing_cycle', in: 'query', required: false, description: 'Filtrar por ciclo de facturación', schema: new OA\Schema(type: 'string', enum: ['monthly', 'yearly']))]
    #[OA\Parameter(name: 'is_active', in: 'query', required: false, description: 'Filtrar por estado', schema: new OA\Schema(type: 'boolean', example: true))]
    #[OA\Parameter(name: 'is_trial', in: 'query', required: false, description: 'Filtrar si es plan de prueba', schema: new OA\Schema(type: 'boolean', example: false))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de planes obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Listado de planes obtenido correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Plan')),
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
        $query = Plan::with('features');

        $query->when($request->filled('name'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->name.'%');
        });

        $query->when($request->filled('billing_cycle'), function ($q) use ($request) {
            $q->where('billing_cycle', $request->billing_cycle);
        });

        $query->when($request->filled('is_active'), function ($q) use ($request) {
            $q->where('is_active', $request->boolean('is_active'));
        });

        $query->when($request->filled('is_trial'), function ($q) use ($request) {
            $q->where('is_trial', $request->boolean('is_trial'));
        });

        $perPage = $request->integer('per_page', 15);
        $plans = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Listado de planes obtenido correctamente.',
            'data' => [
                'items' => PlanResource::collection($plans->items()),
                'total' => $plans->total(),
                'per_page' => $plans->perPage(),
                'current_page' => $plans->currentPage(),
                'last_page' => $plans->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/plans',
        summary: 'Crear plan',
        description: 'Crea un nuevo plan en el sistema.',
        operationId: 'planStore',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'price', 'billing_cycle'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Plan Básico'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Plan ideal para pequeños negocios'),
                new OA\Property(property: 'price', type: 'number', format: 'float', example: 29.99),
                new OA\Property(property: 'billing_cycle', type: 'string', enum: ['monthly', 'yearly'], example: 'monthly'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                new OA\Property(property: 'is_trial', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Plan creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Plan'),
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
    public function store(CreatePlanRequest $request): JsonResponse
    {
        $plan = Plan::create($request->validated());
        $plan->load('features');

        return response()->json([
            'status' => 'success',
            'message' => 'Plan creado correctamente.',
            'data' => PlanResource::make($plan),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/admin/plans/{id}',
        summary: 'Obtener plan por ID',
        description: 'Retorna un plan específico con sus funcionalidades asociadas.',
        operationId: 'planShow',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del plan',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Plan obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Plan obtenido correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Plan'),
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
        description: 'Plan no encontrado',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Plan no encontrado.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['plan' => ['No existe un plan con ese ID']]
                        ),
                    ]
                ),
            ]
        )
    )]
    public function show(string $id): JsonResponse
    {
        $plan = Plan::with('features')->find($id);

        if (! $plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Plan no encontrado.',
                'data' => null,
                'errors' => ['plan' => ['No existe un plan con ese ID']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Plan obtenido correctamente.',
            'data' => PlanResource::make($plan),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/admin/plans/{id}',
        summary: 'Actualizar plan',
        description: 'Actualiza un plan existente.',
        operationId: 'planUpdate',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del plan',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Plan Básico Actualizado'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Descripción actualizada'),
                new OA\Property(property: 'price', type: 'number', format: 'float', example: 39.99),
                new OA\Property(property: 'billing_cycle', type: 'string', enum: ['monthly', 'yearly'], example: 'yearly'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                new OA\Property(property: 'is_trial', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Plan actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Plan actualizado correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Plan'),
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
        description: 'Plan no encontrado',
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
    public function update(UpdatePlanRequest $request, string $id): JsonResponse
    {
        $plan = Plan::find($id);

        if (! $plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Plan no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $plan->update($request->validated());
        $plan->load('features');

        return response()->json([
            'status' => 'success',
            'message' => 'Plan actualizado correctamente.',
            'data' => PlanResource::make($plan),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/admin/plans/{id}',
        summary: 'Eliminar plan',
        description: 'Elimina un plan por ID. No se puede eliminar si tiene tiendas asociadas.',
        operationId: 'planDestroy',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del plan a eliminar',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Plan eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Plan eliminado correctamente.'),
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
        description: 'Plan no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 409,
        description: 'Conflicto - tiene tiendas asociadas',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'No se puede eliminar el plan porque tiene tiendas asociadas.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function destroy(string $id): JsonResponse
    {
        $plan = Plan::find($id);

        if (! $plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Plan no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        if ($plan->stores()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el plan porque tiene tiendas asociadas.',
                'data' => null,
                'errors' => null,
            ], 409);
        }

        $plan->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Plan eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/plans/{id}/sync-features',
        summary: 'Sincronizar funcionalidades del plan',
        description: 'Sincroniza las funcionalidades asociadas a un plan, reemplazando las existentes.',
        operationId: 'planSyncFeatures',
        security: [['sanctum' => []]],
        tags: ['Plans']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del plan',
        schema: new OA\Schema(type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['features'],
            properties: [
                new OA\Property(
                    property: 'features',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        required: ['feature_id'],
                        properties: [
                            new OA\Property(property: 'feature_id', type: 'string', example: '019dd4bc-7318-7094-829b-a02485ba6caf'),
                            new OA\Property(property: 'limit_value', type: 'integer', nullable: true, example: 100),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Funcionalidades sincronizadas correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Funcionalidades sincronizadas correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Plan'),
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
        description: 'Plan no encontrado',
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
    public function syncFeatures(SyncFeaturesRequest $request, string $id): JsonResponse
    {
        $plan = Plan::find($id);

        if (! $plan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Plan no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $syncData = collect($request->features)->mapWithKeys(function ($item) {
            return [
                $item['feature_id'] => ['limit_value' => $item['limit_value'] ?? null],
            ];
        })->toArray();

        $plan->features()->sync($syncData);
        $plan->load('features');

        return response()->json([
            'status' => 'success',
            'message' => 'Funcionalidades sincronizadas correctamente.',
            'data' => PlanResource::make($plan),
            'errors' => null,
        ]);
    }
}
