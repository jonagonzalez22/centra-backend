<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCustomerAddressRequest;
use App\Http\Requests\Api\V1\Store\UpdateCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerAddressController extends Controller
{
    #[OA\Get(
        path: '/store/customers/{customerId}/addresses',
        summary: 'Listar direcciones de un cliente',
        description: 'Retorna la lista paginada de direcciones de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerAddressIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Direcciones de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'type', in: 'query', required: false, description: 'Filtrar por tipo', schema: new OA\Schema(type: 'string', enum: ['billing', 'delivery', 'other']))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de direcciones obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Direcciones obtenidas exitosamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerAddress')),
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
    #[OA\Response(
        response: 404,
        description: 'Cliente no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function index(Request $request, string $customerId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['customer_id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $addresses = $customer->addresses()
            ->with('locality')
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->orderBy('is_main', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Direcciones obtenidas exitosamente.',
            'data' => [
                'items' => CustomerAddressResource::collection($addresses->items()),
                'total' => $addresses->total(),
                'per_page' => $addresses->perPage(),
                'current_page' => $addresses->currentPage(),
                'last_page' => $addresses->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/customers/{customerId}/addresses',
        summary: 'Crear dirección',
        description: 'Crea una nueva dirección para un cliente de la tienda del usuario autenticado.',
        operationId: 'customerAddressStore',
        security: [['sanctum' => []]],
        tags: ['Store - Direcciones de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['locality_id', 'street', 'number', 'postal_code', 'type'],
            properties: [
                new OA\Property(property: 'locality_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'street', type: 'string', maxLength: 255, example: 'Av. Corrientes'),
                new OA\Property(property: 'number', type: 'string', maxLength: 20, example: '1234'),
                new OA\Property(property: 'floor', type: 'string', maxLength: 10, nullable: true, example: '3'),
                new OA\Property(property: 'apartment', type: 'string', maxLength: 10, nullable: true, example: 'A'),
                new OA\Property(property: 'postal_code', type: 'string', maxLength: 20, example: 'C1043AAN'),
                new OA\Property(property: 'latitude', type: 'number', nullable: true, example: -34.603722),
                new OA\Property(property: 'longitude', type: 'number', nullable: true, example: -58.381592),
                new OA\Property(property: 'type', type: 'string', enum: ['billing', 'delivery', 'other'], example: 'billing'),
                new OA\Property(property: 'is_main', type: 'boolean', example: true),
                new OA\Property(property: 'observations', type: 'string', nullable: true, example: 'Casa color roja'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Dirección creada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerAddress'),
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
    public function store(StoreCustomerAddressRequest $request, string $customerId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['customer_id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $address = $customer->addresses()->create([
            'locality_id' => $request->locality_id,
            'street' => $request->street,
            'number' => $request->number,
            'floor' => $request->floor,
            'apartment' => $request->apartment,
            'postal_code' => $request->postal_code,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'type' => $request->type,
            'is_main' => $request->boolean('is_main', false),
            'observations' => $request->observations,
        ]);

        $address->load('locality');

        return response()->json([
            'status' => 'success',
            'message' => 'Dirección creada correctamente.',
            'data' => CustomerAddressResource::make($address),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/store/customers/{customerId}/addresses/{id}',
        summary: 'Obtener dirección por ID',
        description: 'Retorna una dirección específica de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerAddressShow',
        security: [['sanctum' => []]],
        tags: ['Store - Direcciones de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la dirección', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Dirección obtenida correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Dirección obtenida exitosamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerAddress'),
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
        description: 'Dirección no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function show(Request $request, string $customerId, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['customer_id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $address = $customer->addresses()->with('locality')->find($id);

        if (! $address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dirección no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La dirección no existe.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Dirección obtenida exitosamente.',
            'data' => CustomerAddressResource::make($address),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/store/customers/{customerId}/addresses/{id}',
        summary: 'Actualizar dirección',
        description: 'Actualiza una dirección existente de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerAddressUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Direcciones de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la dirección', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'locality_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'street', type: 'string', maxLength: 255, example: 'Av. Santa Fe'),
                new OA\Property(property: 'number', type: 'string', maxLength: 20, example: '5678'),
                new OA\Property(property: 'floor', type: 'string', maxLength: 10, nullable: true, example: '5'),
                new OA\Property(property: 'apartment', type: 'string', maxLength: 10, nullable: true, example: 'B'),
                new OA\Property(property: 'postal_code', type: 'string', maxLength: 20, example: 'C1050ABN'),
                new OA\Property(property: 'latitude', type: 'number', nullable: true, example: -34.600000),
                new OA\Property(property: 'longitude', type: 'number', nullable: true, example: -58.370000),
                new OA\Property(property: 'type', type: 'string', enum: ['billing', 'delivery', 'other'], example: 'delivery'),
                new OA\Property(property: 'is_main', type: 'boolean', example: false),
                new OA\Property(property: 'observations', type: 'string', nullable: true, example: 'Actualizado'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Dirección actualizada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerAddress'),
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
        description: 'Dirección no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function update(UpdateCustomerAddressRequest $request, string $customerId, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['customer_id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $address = $customer->addresses()->find($id);

        if (! $address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dirección no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La dirección no existe.']],
            ], 404);
        }

        $data = $request->validated();

        if ($request->has('is_main')) {
            $data['is_main'] = $request->boolean('is_main');
        }

        $address->update($data);
        $address->load('locality');

        return response()->json([
            'status' => 'success',
            'message' => 'Dirección actualizada correctamente.',
            'data' => CustomerAddressResource::make($address),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/store/customers/{customerId}/addresses/{id}',
        summary: 'Eliminar dirección',
        description: 'Elimina una dirección por ID.',
        operationId: 'customerAddressDestroy',
        security: [['sanctum' => []]],
        tags: ['Store - Direcciones de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la dirección a eliminar', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Dirección eliminada correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Dirección eliminada correctamente.'),
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
        description: 'Dirección no encontrada',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function destroy(Request $request, string $customerId, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $customer = Customer::forStore($storeId)->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cliente no encontrado.',
                'data' => null,
                'errors' => ['customer_id' => ['El cliente no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $address = $customer->addresses()->find($id);

        if (! $address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dirección no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La dirección no existe.']],
            ], 404);
        }

        $address->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Dirección eliminada correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
