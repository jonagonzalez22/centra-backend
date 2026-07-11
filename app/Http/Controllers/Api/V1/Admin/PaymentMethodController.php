<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePaymentMethodRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PaymentMethodController extends Controller
{
    #[OA\Get(
        path: '/admin/payment-methods',
        summary: 'Listar métodos de pago',
        description: 'Retorna la lista paginada de métodos de pago globales. Permite filtrar por nombre, código y estado.',
        operationId: 'paymentMethodIndex',
        security: [['sanctum' => []]],
        tags: ['Admin - Payment Methods']
    )]
    #[OA\Parameter(name: 'name', in: 'query', required: false, description: 'Filtrar por nombre (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'Transferencia'))]
    #[OA\Parameter(name: 'code', in: 'query', required: false, description: 'Filtrar por código (búsqueda parcial)', schema: new OA\Schema(type: 'string', example: 'transfer'))]
    #[OA\Parameter(name: 'is_active', in: 'query', required: false, description: 'Filtrar por estado activo', schema: new OA\Schema(type: 'boolean', example: true))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de métodos de pago obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Métodos de pago obtenidos correctamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PaymentMethod')),
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
        $query = PaymentMethod::query();

        $query->when($request->filled('name'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->name.'%');
        });

        $query->when($request->filled('code'), function ($q) use ($request) {
            $q->where('code', 'like', '%'.$request->code.'%');
        });

        $query->when($request->has('is_active'), function ($q) use ($request) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        $query->orderBy('name');

        $perPage = $request->integer('per_page', 15);
        $paymentMethods = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Métodos de pago obtenidos correctamente.',
            'data' => [
                'items' => PaymentMethodResource::collection($paymentMethods->items()),
                'total' => $paymentMethods->total(),
                'per_page' => $paymentMethods->perPage(),
                'current_page' => $paymentMethods->currentPage(),
                'last_page' => $paymentMethods->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/admin/payment-methods',
        summary: 'Crear método de pago',
        description: 'Crea un nuevo método de pago global en el sistema.',
        operationId: 'paymentMethodStore',
        security: [['sanctum' => []]],
        tags: ['Admin - Payment Methods']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'code'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Transferencia Bancaria'),
                new OA\Property(property: 'code', type: 'string', example: 'transfer'),
                new OA\Property(property: 'icon', type: 'string', nullable: true, example: 'bank-outline'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Método de pago creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Método de pago creado correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PaymentMethod'),
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
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $paymentMethod = PaymentMethod::create($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Método de pago creado correctamente.',
            'data' => PaymentMethodResource::make($paymentMethod),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/admin/payment-methods/{id}',
        summary: 'Obtener método de pago por ID',
        description: 'Retorna un método de pago global específico.',
        operationId: 'paymentMethodShow',
        security: [['sanctum' => []]],
        tags: ['Admin - Payment Methods']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del método de pago',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\Response(
        response: 200,
        description: 'Método de pago obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Método de pago obtenido correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PaymentMethod'),
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
        description: 'Método de pago no encontrado',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'error'),
                        new OA\Property(property: 'message', example: 'Método de pago no encontrado.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['payment_method' => ['No existe un método de pago con ese ID']]
                        ),
                    ]
                ),
            ]
        )
    )]
    public function show(string $id): JsonResponse
    {
        $paymentMethod = PaymentMethod::find($id);

        if (! $paymentMethod) {
            return response()->json([
                'status' => 'error',
                'message' => 'Método de pago no encontrado.',
                'data' => null,
                'errors' => ['payment_method' => ['No existe un método de pago con ese ID']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Método de pago obtenido correctamente.',
            'data' => PaymentMethodResource::make($paymentMethod),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/admin/payment-methods/{id}',
        summary: 'Actualizar método de pago',
        description: 'Actualiza un método de pago global existente.',
        operationId: 'paymentMethodUpdate',
        security: [['sanctum' => []]],
        tags: ['Admin - Payment Methods']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del método de pago',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Transferencia Bancaria Actualizada'),
                new OA\Property(property: 'code', type: 'string', example: 'bank_transfer'),
                new OA\Property(property: 'icon', type: 'string', nullable: true, example: 'bank-outline'),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Método de pago actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Método de pago actualizado correctamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PaymentMethod'),
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
        description: 'Método de pago no encontrado',
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
    public function update(UpdatePaymentMethodRequest $request, string $id): JsonResponse
    {
        $paymentMethod = PaymentMethod::find($id);

        if (! $paymentMethod) {
            return response()->json([
                'status' => 'error',
                'message' => 'Método de pago no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $paymentMethod->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Método de pago actualizado correctamente.',
            'data' => PaymentMethodResource::make($paymentMethod),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/admin/payment-methods/{id}',
        summary: 'Eliminar método de pago',
        description: 'Elimina un método de pago global por ID. No se puede eliminar si tiene tiendas asociadas.',
        operationId: 'paymentMethodDestroy',
        security: [['sanctum' => []]],
        tags: ['Admin - Payment Methods']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del método de pago a eliminar',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\Response(
        response: 200,
        description: 'Método de pago eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Método de pago eliminado correctamente.'),
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
        description: 'Método de pago no encontrado',
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
                        new OA\Property(property: 'message', example: 'No se puede eliminar el método de pago porque tiene tiendas asociadas.'),
                        new OA\Property(property: 'data', nullable: true, example: null),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                ),
            ]
        )
    )]
    public function destroy(string $id): JsonResponse
    {
        $paymentMethod = PaymentMethod::find($id);

        if (! $paymentMethod) {
            return response()->json([
                'status' => 'error',
                'message' => 'Método de pago no encontrado.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        if ($paymentMethod->storePaymentMethods()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el método de pago porque tiene tiendas asociadas.',
                'data' => null,
                'errors' => null,
            ], 409);
        }

        $paymentMethod->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Método de pago eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
