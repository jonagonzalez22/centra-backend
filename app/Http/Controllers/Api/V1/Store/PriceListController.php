<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StorePriceListRequest;
use App\Http\Requests\Api\V1\Store\UpdatePriceListRequest;
use App\Http\Resources\PriceListResource;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PriceListController extends Controller
{
    #[OA\Get(
        path: '/store/price-lists',
        summary: 'Listar listas de precios',
        description: 'Retorna las listas de precios de la tienda autenticada.',
        operationId: 'priceListIndex',
        security: [['sanctum' => []]],
        tags: ['Store - Listas de precios']
    )]
    #[OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'Listas de precios obtenidas correctamente', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Listas de precios obtenidas exitosamente.'),
        new OA\Property(property: 'data', properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceList')),
            new OA\Property(property: 'total', type: 'integer', example: 1),
            new OA\Property(property: 'per_page', type: 'integer', example: 15),
            new OA\Property(property: 'current_page', type: 'integer', example: 1),
            new OA\Property(property: 'last_page', type: 'integer', example: 1),
        ]),
        new OA\Property(property: 'errors', nullable: true, example: null),
    ]))]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceLists = PriceList::forStore($request->user()->store_id)
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->search.'%'))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Listas de precios obtenidas exitosamente.',
            'data' => [
                'items' => PriceListResource::collection($priceLists->items()),
                'total' => $priceLists->total(),
                'per_page' => $priceLists->perPage(),
                'current_page' => $priceLists->currentPage(),
                'last_page' => $priceLists->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    #[OA\Post(
        path: '/store/price-lists',
        summary: 'Crear lista de precios',
        operationId: 'priceListStore',
        security: [['sanctum' => []]],
        tags: ['Store - Listas de precios']
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name', 'discount_percentage'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Mayorista'),
            new OA\Property(property: 'discount_percentage', type: 'string', format: 'decimal', example: '10.00', minimum: 0, maximum: 100),
            new OA\Property(property: 'is_active', type: 'boolean', example: true),
        ]
    ))]
    #[OA\Response(response: 201, description: 'Lista de precios creada correctamente', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Lista de precios creada exitosamente.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceList'),
        new OA\Property(property: 'errors', nullable: true, example: null),
    ]))]
    public function store(StorePriceListRequest $request): JsonResponse
    {
        $priceList = PriceList::create([
            'store_id' => $request->user()->store_id,
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Lista de precios creada exitosamente.',
            'data' => PriceListResource::make($priceList),
            'errors' => null,
        ], 201);
    }

    #[OA\Get(
        path: '/store/price-lists/{price_list}',
        summary: 'Obtener lista de precios',
        operationId: 'priceListShow',
        security: [['sanctum' => []]],
        tags: ['Store - Listas de precios']
    )]
    #[OA\Parameter(name: 'price_list', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Lista de precios obtenida correctamente', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Lista de precios obtenida exitosamente.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceList'),
        new OA\Property(property: 'errors', nullable: true, example: null),
    ]))]
    #[OA\Response(response: 404, description: 'Lista de precios no encontrada')]
    public function show(Request $request, string $priceList): JsonResponse
    {
        return $this->respond($request, $priceList);
    }

    #[OA\Put(
        path: '/store/price-lists/{price_list}',
        summary: 'Actualizar lista de precios',
        operationId: 'priceListUpdate',
        security: [['sanctum' => []]],
        tags: ['Store - Listas de precios']
    )]
    #[OA\Parameter(name: 'price_list', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Distribuidor'),
        new OA\Property(property: 'discount_percentage', type: 'string', format: 'decimal', example: '15.00', minimum: 0, maximum: 100),
        new OA\Property(property: 'is_active', type: 'boolean', example: false),
    ]))]
    #[OA\Response(response: 200, description: 'Lista de precios actualizada correctamente', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Lista de precios actualizada exitosamente.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/PriceList'),
        new OA\Property(property: 'errors', nullable: true, example: null),
    ]))]
    public function update(UpdatePriceListRequest $request, string $priceList): JsonResponse
    {
        $list = PriceList::forStore($request->user()->store_id)->find($priceList);

        if (! $list) {
            return $this->notFound();
        }

        $list->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Lista de precios actualizada exitosamente.',
            'data' => PriceListResource::make($list),
            'errors' => null,
        ]);
    }

    #[OA\Delete(
        path: '/store/price-lists/{price_list}',
        summary: 'Eliminar lista de precios',
        operationId: 'priceListDestroy',
        security: [['sanctum' => []]],
        tags: ['Store - Listas de precios']
    )]
    #[OA\Parameter(name: 'price_list', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Lista de precios eliminada correctamente', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'message', type: 'string', example: 'Lista de precios eliminada exitosamente.'),
        new OA\Property(property: 'data', nullable: true, example: null),
        new OA\Property(property: 'errors', nullable: true, example: null),
    ]))]
    public function destroy(Request $request, string $priceList): JsonResponse
    {
        $list = PriceList::forStore($request->user()->store_id)->find($priceList);

        if (! $list) {
            return $this->notFound();
        }

        $list->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Lista de precios eliminada exitosamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    private function respond(Request $request, string $id): JsonResponse
    {
        $priceList = PriceList::forStore($request->user()->store_id)->find($id);

        if (! $priceList) {
            return $this->notFound();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Lista de precios obtenida exitosamente.',
            'data' => PriceListResource::make($priceList),
            'errors' => null,
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Lista de precios no encontrada.',
            'data' => null,
            'errors' => ['id' => ['La lista de precios no existe o no pertenece a tu tienda.']],
        ], 404);
    }
}
