<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Store\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerController extends Controller
{
    #[OA\Get(
        path: '/store/customers',
        summary: 'Listar clientes',
        description: 'Retorna la lista paginada de clientes de la tienda del usuario autenticado.',
        operationId: 'customerIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Clientes']
    )]
    #[OA\Parameter(name: 'search', in: 'query', required: false, description: 'Búsqueda por nombre, documento o código', schema: new OA\Schema(type: 'string', example: 'Juan'))]
    #[OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filtrar por estado', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive']))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de clientes obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Clientes obtenidos exitosamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Customer')),
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

        $customers = Customer::forStore($storeId)
            ->with(['documentType', 'commercialGroup'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('search_text', 'like', '%'.$request->search.'%');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->orderBy('display_name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Clientes obtenidos exitosamente.',
            'data' => [
                'items' => CustomerResource::collection($customers->items()),
                'total' => $customers->total(),
                'per_page' => $customers->perPage(),
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/customers',
        summary: 'Crear cliente',
        description: 'Crea un nuevo cliente en la tienda del usuario autenticado.',
        operationId: 'customerStore',
        security: [['sanctum' => []]],
        tags: ['Store - Clientes']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['display_name', 'document_type_id', 'document_number'],
            properties: [
                new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Juan Pérez'),
                new OA\Property(property: 'first_name', type: 'string', nullable: true, example: 'Juan'),
                new OA\Property(property: 'last_name', type: 'string', nullable: true, example: 'Pérez'),
                new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Pérez S.A.'),
                new OA\Property(property: 'document_type_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                new OA\Property(property: 'document_number', type: 'string', example: '20-12345678-5'),
                new OA\Property(property: 'commercial_group_id', type: 'string', format: 'uuid', nullable: true, example: '550e8400-e29b-41d4-a716-446655440001'),
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], example: 'active'),
                new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Cliente frecuente'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Cliente creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
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
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::create([
            'store_id' => $storeId,
            'display_name' => $request->display_name,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'company_name' => $request->company_name,
            'document_type_id' => $request->document_type_id,
            'document_number' => $request->document_number,
            'commercial_group_id' => $request->commercial_group_id,
            'status' => $request->status ?? 'active',
            'notes' => $request->notes,
        ]);

        $customer->load(['documentType', 'commercialGroup']);

        return response()->json([
            'status' => 'success',
            'message' => 'Cliente creado correctamente.',
            'data' => CustomerResource::make($customer),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/store/customers/{id}',
        summary: 'Obtener cliente por ID',
        description: 'Retorna un cliente específico de la tienda del usuario autenticado.',
        operationId: 'customerShow',
        security: [['sanctum' => []]],
        tags: ['Store - Clientes']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del cliente',
        schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')
    )]
    #[OA\Response(
        response: 200,
        description: 'Cliente obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Cliente obtenido exitosamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
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
        description: 'Cliente no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)
            ->with(['documentType', 'commercialGroup'])
            ->find($id);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cliente obtenido exitosamente.',
            'data' => CustomerResource::make($customer),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/store/customers/{id}',
        summary: 'Actualizar cliente',
        description: 'Actualiza un cliente existente de la tienda del usuario autenticado.',
        operationId: 'customerUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Clientes']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del cliente',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Juan Pérez Actualizado'),
                new OA\Property(property: 'first_name', type: 'string', nullable: true, example: 'Juan'),
                new OA\Property(property: 'last_name', type: 'string', nullable: true, example: 'Pérez'),
                new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Pérez S.A.'),
                new OA\Property(property: 'document_type_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'document_number', type: 'string', example: '27-12345678-5'),
                new OA\Property(property: 'commercial_group_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Cliente actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
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
        description: 'Cliente no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function update(UpdateCustomerRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)
            ->with(['documentType', 'commercialGroup'])
            ->find($id);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $customer->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Cliente actualizado correctamente.',
            'data' => CustomerResource::make($customer),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/store/customers/{id}',
        summary: 'Eliminar cliente',
        description: 'Elimina un cliente por ID.',
        operationId: 'customerDestroy',
        security: [['sanctum' => []]],
        tags: ['Store - Clientes']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID del cliente a eliminar',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\Response(
        response: 200,
        description: 'Cliente eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Cliente eliminado correctamente.'),
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
        description: 'Cliente no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($id);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Cliente eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
