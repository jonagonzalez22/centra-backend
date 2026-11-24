<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCustomerAddressRequest;
use App\Http\Requests\Api\V1\Store\UpdateCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['locality', 'locality.province'])
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

        try {
            $address = DB::transaction(function () use ($customer, $request, $storeId) {
                $customer = Customer::forStore($storeId)->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $this->ensureDeliveryAddressIsUnique($customer, $request->type);

                $data = $this->locationDataForCreate($request);

                return $customer->addresses()->create([
                    'locality_id' => $request->locality_id,
                    'street' => $request->street,
                    'number' => $request->number,
                    'floor' => $request->floor,
                    'apartment' => $request->apartment,
                    'postal_code' => $request->postal_code,
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'type' => $request->type,
                    'is_main' => $request->boolean('is_main', false),
                    'observations' => $request->observations,
                    'location_status' => $data['location_status'],
                    'location_source' => $data['location_source'],
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isDeliveryAddressUniqueViolation($exception)) {
                $this->deliveryAddressUniqueError();
            }

            throw $exception;
        }

        $address->load(['locality', 'locality.province']);

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

        $address = $customer->addresses()->with(['locality', 'locality.province'])->find($id);

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

        try {
            $address = DB::transaction(function () use ($address, $customer, $data, $storeId) {
                $customer = Customer::forStore($storeId)->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $address = $customer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();
                $this->ensureDeliveryAddressIsUnique($customer, $data['type'] ?? $address->type, $address->id);
                $data = $this->locationDataForUpdate($data, $address);
                $address->update($data);

                return $address;
            });
        } catch (QueryException $exception) {
            if ($this->isDeliveryAddressUniqueViolation($exception)) {
                $this->deliveryAddressUniqueError();
            }

            throw $exception;
        }
        $address->load(['locality', 'locality.province']);

        return response()->json([
            'status' => 'success',
            'message' => 'Dirección actualizada correctamente.',
            'data' => CustomerAddressResource::make($address),
            'errors' => null,
        ]);
    }

    private function ensureDeliveryAddressIsUnique(Customer $customer, string $type, ?string $exceptAddressId = null): void
    {
        if ($type !== 'delivery') {
            return;
        }

        $query = $customer->addresses()->where('type', 'delivery');
        if ($exceptAddressId !== null) {
            $query->whereKeyNot($exceptAddressId);
        }

        if ($query->exists()) {
            $this->deliveryAddressUniqueError();
        }
    }

    /** @return array{latitude: ?float, longitude: ?float, location_status: string, location_source: ?string} */
    private function locationDataForCreate(StoreCustomerAddressRequest $request): array
    {
        return $this->buildLocationData(
            $request->input('latitude'),
            $request->input('longitude'),
            $request->boolean('confirm_location'),
            $request->input('location_source'),
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function locationDataForUpdate(array $data, CustomerAddress $address): array
    {
        $addressChanged = array_key_exists('street', $data) && $data['street'] !== $address->street
            || array_key_exists('number', $data) && $data['number'] !== $address->number
            || array_key_exists('locality_id', $data) && $data['locality_id'] !== $address->locality_id;

        if ($addressChanged) {
            $confirm = (bool) ($data['confirm_location'] ?? false);
            $hasCompleteCoordinates = array_key_exists('latitude', $data) && array_key_exists('longitude', $data);

            if ($confirm && $hasCompleteCoordinates) {
                unset($data['confirm_location']);

                return array_merge($data, $this->buildLocationData(
                    $data['latitude'],
                    $data['longitude'],
                    true,
                    $data['location_source'] ?? $address->location_source,
                ));
            }

            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['location_status'] = 'none';
            $data['location_source'] = null;
            unset($data['confirm_location']);

            return $data;
        }

        $coordinatesChanged = array_key_exists('latitude', $data) || array_key_exists('longitude', $data);
        $confirm = (bool) ($data['confirm_location'] ?? false);
        unset($data['confirm_location']);

        if (! $coordinatesChanged && ! $confirm) {
            return $data;
        }

        $location = $this->buildLocationData(
            $data['latitude'] ?? $address->latitude,
            $data['longitude'] ?? $address->longitude,
            $confirm,
            $data['location_source'] ?? $address->location_source,
        );

        return array_merge($data, $location);
    }

    /** @return array{latitude: ?float, longitude: ?float, location_status: string, location_source: ?string} */
    private function buildLocationData(mixed $latitude, mixed $longitude, bool $confirm, mixed $source): array
    {
        if ($latitude === null && $longitude === null) {
            if ($confirm) {
                $this->locationValidationError('No se puede confirmar una ubicación sin coordenadas.');
            }

            return ['latitude' => null, 'longitude' => null, 'location_status' => 'none', 'location_source' => null];
        }
        if ($latitude === null || $longitude === null) {
            $this->locationValidationError('La ubicación debe incluir latitud y longitud.');
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'location_status' => $confirm ? 'confirmed' : 'pending',
            'location_source' => is_string($source) ? $source : null,
        ];
    }

    private function locationValidationError(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error', 'message' => 'Error de validación.', 'data' => null,
            'errors' => ['location' => [$message]],
        ], 422));
    }

    private function isDeliveryAddressUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'customer_addresses_one_delivery_per_customer');
    }

    private function deliveryAddressUniqueError(): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => ['type' => ['El cliente ya tiene un domicilio de Entrega.']],
        ], 422));
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
