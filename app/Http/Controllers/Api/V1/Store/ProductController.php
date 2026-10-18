<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreProductRequest;
use App\Http\Requests\Api\V1\Store\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStopItem;
use App\Services\ProductStockAssociationValidator;
use App\Support\QuantityMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private readonly ProductStockAssociationValidator $associationValidator) {}

    /**
     * Display a listing of products for the authenticated user's store.
     *
     * @OA\Get(
     *   path="/store/products",
     *   summary="Listar productos de la tienda",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="category_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por categoría"),
     *   @OA\Parameter(name="name", in="query", @OA\Schema(type="string"), description="Filtrar por nombre (búsqueda parcial)"),
     *   @OA\Parameter(name="sku", in="query", @OA\Schema(type="string"), description="Filtrar por SKU (búsqueda parcial)"),
     *   @OA\Parameter(name="barcode", in="query", @OA\Schema(type="string"), description="Filtrar por código de barras"),
     *   @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean"), description="Filtrar por estado activo"),
     *   @OA\Parameter(name="is_sellable", in="query", @OA\Schema(type="boolean"), description="Filtrar por disponibilidad para venta"),
     *   @OA\Parameter(name="stock_type", in="query", @OA\Schema(type="string", enum={"own", "shared"}), description="Filtrar por tipo de stock"),
     *   @OA\Parameter(name="sort_by", in="query", @OA\Schema(type="string", enum={"name", "sku", "price", "stock", "created_at"}), description="Campo por el cual ordenar"),
     *   @OA\Parameter(name="sort_dir", in="query", @OA\Schema(type="string", enum={"asc", "desc"}), description="Dirección del ordenamiento"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Productos obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Productos obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/ProductResource")),
     *         @OA\Property(property="total", type="integer", example=25),
     *         @OA\Property(property="per_page", type="integer", example=15),
     *         @OA\Property(property="current_page", type="integer", example=1),
     *         @OA\Property(property="last_page", type="integer", example=2)
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'stock_type' => ['nullable', 'string', Rule::in(['own', 'shared'])],
            'is_sellable' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ]);

        $storeId = $request->user()->store_id;

        $query = Product::forStore($storeId)
            ->with(['category', 'stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit'])
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->category_id);
            })
            ->when($request->filled('name'), function ($query) use ($request) {
                $query->where('name', 'like', '%'.$request->name.'%');
            })
            ->when($request->filled('sku'), function ($query) use ($request) {
                $query->where('sku', 'like', '%'.$request->sku.'%');
            })
            ->when($request->filled('barcode'), function ($query) use ($request) {
                $query->where('barcode', $request->barcode);
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->has('is_sellable'), function ($query) use ($request) {
                $query->where('is_sellable', filter_var($request->is_sellable, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->stock_type === 'own', function ($query) {
                $query->whereNull('stock_source_product_id');
            })
            ->when($request->stock_type === 'shared', function ($query) {
                $query->whereNotNull('stock_source_product_id');
            });

        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $allowedSorts = ['name', 'sku', 'price', 'stock', 'created_at'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $products = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Productos obtenidos exitosamente.',
            'data' => [
                'items' => ProductResource::collection($products->items()),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a newly created product.
     *
     * @OA\Post(
     *   path="/store/products",
     *   summary="Crear un nuevo producto",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"name", "sku", "category_id", "price"},
     *
     *       @OA\Property(property="name", type="string", maxLength=255, example="Pintura Látex Blanca"),
     *       @OA\Property(property="sku", type="string", maxLength=100, example="PNT-LTX-001"),
     *       @OA\Property(property="category_id", type="string", format="uuid", example="uuid-categoria"),
     *       @OA\Property(property="barcode", type="string", maxLength=100, example="7501234567890", nullable=true),
     *       @OA\Property(property="description", type="string", example="Pintura látex premium para interiores", nullable=true),
     *       @OA\Property(property="price", type="number", example=259.99),
     *       @OA\Property(property="cost", type="number", example=150.00, nullable=true),
     *       @OA\Property(property="stock", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="100.0000", nullable=true),
     *       @OA\Property(property="stock_min", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="10.0000", nullable=true),
     *       @OA\Property(property="stock_measurement_unit_id", type="string", format="uuid", nullable=true),
     *       @OA\Property(property="sale_quantity_step", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="1.0000", nullable=true),
     *       @OA\Property(property="is_active", type="boolean", example=true, nullable=true),
     *       @OA\Property(property="is_sellable", type="boolean", example=true, nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Producto creado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Producto creado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/ProductResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $validated = $request->validated();

        return DB::transaction(function () use ($request, $storeId, $validated): JsonResponse {
            $source = isset($validated['stock_source_product_id'])
                ? Product::forStore($storeId)->whereKey($validated['stock_source_product_id'])->lockForUpdate()->first()
                : null;
            if (array_key_exists('stock_source_product_id', $validated) && $validated['stock_source_product_id'] !== null && ! $source) {
                return $this->validationResponse('stock_source_product_id', 'El producto fuente no existe o no pertenece a tu tienda.');
            }

            $associated = $source !== null;
            if (! $associated && ($validated['stock_consumption_quantity'] ?? null) !== null) {
                return $this->validationResponse('stock_consumption_quantity', 'Un producto sin fuente de stock no puede tener factor de consumo.');
            }
            if ($associated && (! QuantityMath::isZero($validated['stock'] ?? '0') || ! QuantityMath::isZero($validated['stock_min'] ?? '0'))) {
                return $this->validationResponse('stock', 'Un producto asociado debe crearse sin stock físico ni stock mínimo propios.');
            }
            $product = new Product([
                'store_id' => $storeId,
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'category_id' => $validated['category_id'],
                'barcode' => $validated['barcode'] ?? null,
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'cost' => $validated['cost'] ?? null,
                'stock' => $associated ? '0.0000' : ($validated['stock'] ?? 0),
                'stock_reserved' => 0,
                'stock_min' => $associated ? '0.0000' : ($validated['stock_min'] ?? 0),
                'stock_measurement_unit_id' => $validated['stock_measurement_unit_id'] ?? null,
                'stock_source_product_id' => $source?->id,
                'stock_consumption_quantity' => $associated ? ($validated['stock_consumption_quantity'] ?? null) : null,
                'sale_quantity_step' => $validated['sale_quantity_step'] ?? '1.0000',
                'is_active' => $request->boolean('is_active', true),
                'is_sellable' => $request->boolean('is_sellable', true),
            ]);
            try {
                $this->associationValidator->validate($product, $source, $product->stock_consumption_quantity);
            } catch (\InvalidArgumentException $exception) {
                return $this->validationResponse('stock_source_product_id', $exception->getMessage());
            }
            $product->save();

            $product->load(['category', 'stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit']);

            return response()->json([
                'status' => 'success', 'message' => 'Producto creado exitosamente.',
                'data' => ProductResource::make($product), 'errors' => null,
            ], 201);
        });
    }

    /**
     * Display the specified product.
     *
     * @OA\Get(
     *   path="/store/products/{id}",
     *   summary="Ver un producto específico",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Producto obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Producto obtenido exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/ProductResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Producto no encontrado")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $product = Product::forStore($storeId)->with(['category', 'stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit'])->find($id);

        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Producto no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El producto no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Producto obtenido exitosamente.',
            'data' => ProductResource::make($product),
            'errors' => null,
        ], 200);
    }

    /**
     * Update the specified product.
     *
     * @OA\Put(
     *   path="/store/products/{id}",
     *   summary="Actualizar un producto",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="name", type="string", maxLength=255, example="Pintura Látex Blanca Actualizada", nullable=true),
     *       @OA\Property(property="sku", type="string", maxLength=100, example="PNT-LTX-002", nullable=true),
     *       @OA\Property(property="category_id", type="string", format="uuid", example="uuid-categoria", nullable=true),
     *       @OA\Property(property="barcode", type="string", maxLength=100, example="7501234567891", nullable=true),
     *       @OA\Property(property="description", type="string", example="Descripción actualizada", nullable=true),
     *       @OA\Property(property="price", type="number", example=279.99, nullable=true),
     *       @OA\Property(property="cost", type="number", example=160.00, nullable=true),
     *       @OA\Property(property="stock", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="150.0000", nullable=true),
     *       @OA\Property(property="stock_min", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="15.0000", nullable=true),
     *       @OA\Property(property="stock_measurement_unit_id", type="string", format="uuid", nullable=true),
     *       @OA\Property(property="sale_quantity_step", type="string", pattern="^\\d+(?:\\.\\d{1,4})?$", example="1.0000", nullable=true),
     *       @OA\Property(property="is_active", type="boolean", example=false, nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Producto actualizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Producto actualizado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/ProductResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Producto no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $validated = $request->validated();

        return DB::transaction(function () use ($storeId, $id, $validated): JsonResponse {
            $product = Product::forStore($storeId)->whereKey($id)->lockForUpdate()->first();

            if (! $product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Producto no encontrado.',
                    'data' => null,
                    'errors' => ['id' => ['El producto no existe o no pertenece a tu tienda.']],
                ], 404);
            }

            $nextSourceId = array_key_exists('stock_source_product_id', $validated)
                ? $validated['stock_source_product_id']
                : $product->stock_source_product_id;
            $nextFactor = array_key_exists('stock_consumption_quantity', $validated)
                ? $validated['stock_consumption_quantity']
                : $product->stock_consumption_quantity;
            $associationChanged = $nextSourceId !== $product->stock_source_product_id
                || $nextFactor !== $product->stock_consumption_quantity;
            $nextIsAssociated = $nextSourceId !== null;

            if ($associationChanged && $this->hasActiveCommercialCommitments($product)) {
                return $this->validationResponse('stock_source_product_id', 'No se puede cambiar la fuente o el consumo mientras existan compromisos comerciales o logísticos activos.');
            }
            if ($nextIsAssociated && ! QuantityMath::isZero($product->stock)) {
                return $this->validationResponse('stock_source_product_id', 'Para usar stock de otro producto, el stock físico propio debe ser cero. Realizá primero un ajuste de inventario explícito.');
            }
            if ($nextIsAssociated && $product->stockDependentProducts()->exists()) {
                return $this->validationResponse('stock_source_product_id', 'No se puede usar stock de otro producto porque este producto ya es fuente de stock de otros productos.');
            }

            $source = $nextSourceId === null ? null : Product::forStore($storeId)->whereKey($nextSourceId)->lockForUpdate()->first();
            if ($nextSourceId !== null && ! $source) {
                return $this->validationResponse('stock_source_product_id', 'El producto fuente no existe o no pertenece a tu tienda.');
            }

            $candidate = clone $product;
            $candidate->stock_source_product_id = $nextSourceId;
            $candidate->stock_consumption_quantity = $nextFactor;
            $candidate->sale_quantity_step = $validated['sale_quantity_step'] ?? $product->sale_quantity_step;
            if ($nextIsAssociated) {
                $candidate->stock = '0.0000';
                $candidate->stock_reserved = '0.0000';
                $candidate->stock_min = '0.0000';
            }
            try {
                $this->associationValidator->validate($candidate, $source, $nextFactor);
            } catch (\InvalidArgumentException $exception) {
                return $this->validationResponse('stock_source_product_id', $exception->getMessage());
            }

            if ($product->stock_source_product_id !== null && array_key_exists('stock', $validated)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El stock de este producto se administra desde su producto fuente.',
                    'data' => null,
                    'errors' => [
                        'stock' => ['El stock de este producto se administra desde su producto fuente.'],
                    ],
                ], 422);
            }

            $measurementUnitChanged = array_key_exists('stock_measurement_unit_id', $validated)
                && $validated['stock_measurement_unit_id'] !== $product->stock_measurement_unit_id;

            if ($measurementUnitChanged && ! array_key_exists('sale_quantity_step', $validated)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación.',
                    'data' => null,
                    'errors' => [
                        'sale_quantity_step' => [
                            'Al cambiar la unidad de medida se debe informar también la cantidad mínima de venta.',
                        ],
                    ],
                ], 422);
            }

            if ($measurementUnitChanged && $this->cannotChangeStockMeasurementUnit($product)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede cambiar la unidad de medida mientras el producto tenga cantidades reservadas o compromisos activos.',
                    'data' => null,
                    'errors' => [
                        'stock_measurement_unit_id' => [
                            'La unidad de medida sólo puede cambiarse cuando no hay cantidades reservadas ni compromisos activos.',
                        ],
                    ],
                ], 422);
            }

            if (
                array_key_exists('sale_quantity_step', $validated)
                && QuantityMath::compare($validated['sale_quantity_step'], $product->sale_quantity_step) !== 0
                && $this->hasActiveCommercialCommitments($product)
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede cambiar el paso de venta mientras existan compromisos comerciales activos.',
                    'data' => null,
                    'errors' => [
                        'sale_quantity_step' => [
                            'El paso de venta sólo puede cambiarse cuando no hay pedidos, reservas o rutas activas.',
                        ],
                    ],
                ], 422);
            }

            if (! $product->is_active && ($validated['is_active'] ?? true) === false) {
                // already inactive: nothing to validate
            } elseif (($validated['is_active'] ?? true) === false && $product->stockDependentProducts()->where('is_active', true)->exists()) {
                return $this->validationResponse('is_active', 'No se puede desactivar este producto porque es fuente de stock de otros productos activos.');
            }

            if ($nextIsAssociated) {
                $validated['stock'] = '0.0000';
                $validated['stock_min'] = '0.0000';
            }
            $validated['stock_source_product_id'] = $nextSourceId;
            $validated['stock_consumption_quantity'] = $nextFactor;
            $product->update($validated);
            $product->load(['category', 'stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit']);

            return response()->json([
                'status' => 'success',
                'message' => 'Producto actualizado exitosamente.',
                'data' => ProductResource::make($product),
                'errors' => null,
            ], 200);
        });
    }

    private function cannotChangeStockMeasurementUnit(Product $product): bool
    {
        return QuantityMath::isPositive($product->stock_reserved)
            || $this->hasActiveCommercialCommitments($product);
    }

    private function hasActiveCommercialCommitments(Product $product): bool
    {
        return QuantityMath::isPositive($product->stock_reserved)
            || OperationItem::query()
                ->where('product_id', $product->id)
                ->whereHas('operation', fn ($query) => $query
                    ->where('type', 'order')
                    ->whereIn('status', ['open', 'confirmed', 'partially_delivered']))
                ->exists()
            || RouteStopItem::query()
                ->where('product_id', $product->id)
                ->whereHas('routeStop.route', fn ($query) => $query->whereIn(
                    'status',
                    ['draft', 'planned', 'loaded', 'dispatched', 'awaiting_reconciliation']
                ))
                ->exists();
    }

    private function validationResponse(string $field, string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Error de validación.', 'data' => null, 'errors' => [$field => [$message]]], 422);
    }

    /**
     * Remove the specified product.
     *
     * @OA\Delete(
     *   path="/store/products/{id}",
     *   summary="Eliminar un producto",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Producto eliminado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Producto eliminado exitosamente."),
     *       @OA\Property(property="data", type="null", example=null),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Producto no encontrado")
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $product = Product::forStore($storeId)->find($id);

        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Producto no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El producto no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $product->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Producto eliminado exitosamente.',
            'data' => null,
            'errors' => null,
        ], 200);
    }
}
