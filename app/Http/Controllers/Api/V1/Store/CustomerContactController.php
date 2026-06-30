<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCustomerContactRequest;
use App\Http\Requests\Api\V1\Store\UpdateCustomerContactRequest;
use App\Http\Resources\CustomerContactResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerContactController extends Controller
{
    #[OA\Get(
        path: '/store/customers/{customerId}/contacts',
        summary: 'Listar contactos de un cliente',
        description: 'Retorna la lista paginada de contactos de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerContactIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Contactos de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Resultados por página (default: 15)', schema: new OA\Schema(type: 'integer', example: 15))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número de página', schema: new OA\Schema(type: 'integer', example: 1))]
    #[OA\Response(
        response: 200,
        description: 'Listado de contactos obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Contactos obtenidos exitosamente.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerContact')),
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

        $contacts = $customer->contacts()
            ->orderBy('is_main', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Contactos obtenidos exitosamente.',
            'data' => [
                'items' => CustomerContactResource::collection($contacts->items()),
                'total' => $contacts->total(),
                'per_page' => $contacts->perPage(),
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/customers/{customerId}/contacts',
        summary: 'Crear contacto',
        description: 'Crea un nuevo contacto para un cliente de la tienda del usuario autenticado.',
        operationId: 'customerContactStore',
        security: [['sanctum' => []]],
        tags: ['Store - Contactos de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Juan Pérez'),
                new OA\Property(property: 'position', type: 'string', maxLength: 255, nullable: true, example: 'Gerente'),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'juan@ejemplo.com'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true, example: '+54 11 1234-5678'),
                new OA\Property(property: 'is_main', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Contacto creado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerContact'),
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
    public function store(StoreCustomerContactRequest $request, string $customerId): JsonResponse
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

        $contact = $customer->contacts()->create([
            'name' => $request->name,
            'position' => $request->position,
            'email' => $request->email,
            'phone' => $request->phone,
            'is_main' => $request->boolean('is_main', false),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contacto creado correctamente.',
            'data' => CustomerContactResource::make($contact),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/store/customers/{customerId}/contacts/{id}',
        summary: 'Obtener contacto por ID',
        description: 'Retorna un contacto específico de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerContactShow',
        security: [['sanctum' => []]],
        tags: ['Store - Contactos de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID del contacto', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Contacto obtenido correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Contacto obtenido exitosamente.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerContact'),
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
        description: 'Contacto no encontrado',
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

        $contact = $customer->contacts()->find($id);

        if (! $contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contacto no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El contacto no existe.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Contacto obtenido exitosamente.',
            'data' => CustomerContactResource::make($contact),
            'errors' => null,
        ]);
    }

    #[OA\Put(
        path: '/store/customers/{customerId}/contacts/{id}',
        summary: 'Actualizar contacto',
        description: 'Actualiza un contacto existente de un cliente de la tienda del usuario autenticado.',
        operationId: 'customerContactUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Contactos de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID del contacto', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'María García'),
                new OA\Property(property: 'position', type: 'string', maxLength: 255, nullable: true, example: 'CEO'),
                new OA\Property(property: 'email', type: 'string', nullable: true, example: 'maria@ejemplo.com'),
                new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true, example: '+54 11 9876-5432'),
                new OA\Property(property: 'is_main', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Contacto actualizado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/CustomerContact'),
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
        description: 'Contacto no encontrado',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    #[OA\Response(
        response: 422,
        description: 'Error de validación',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
    )]
    public function update(UpdateCustomerContactRequest $request, string $customerId, string $id): JsonResponse
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

        $contact = $customer->contacts()->find($id);

        if (! $contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contacto no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El contacto no existe.']],
            ], 404);
        }

        $data = $request->validated();

        if ($request->has('is_main')) {
            $data['is_main'] = $request->boolean('is_main');
        }

        $contact->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Contacto actualizado correctamente.',
            'data' => CustomerContactResource::make($contact),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/store/customers/{customerId}/contacts/{id}',
        summary: 'Eliminar contacto',
        description: 'Elimina un contacto por ID.',
        operationId: 'customerContactDestroy',
        security: [['sanctum' => []]],
        tags: ['Store - Contactos de Clientes']
    )]
    #[OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'ID del cliente', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID del contacto a eliminar', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Contacto eliminado correctamente',
        content: new OA\JsonContent(
            allOf: [
                new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                new OA\Schema(
                    properties: [
                        new OA\Property(property: 'status', example: 'success'),
                        new OA\Property(property: 'message', example: 'Contacto eliminado correctamente.'),
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
        description: 'Contacto no encontrado',
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

        $contact = $customer->contacts()->find($id);

        if (! $contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contacto no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El contacto no existe.']],
            ], 404);
        }

        $contact->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Contacto eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
