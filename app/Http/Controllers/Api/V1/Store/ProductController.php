<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreProductRequest;
use App\Http\Requests\Api\V1\Store\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{

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
    $storeId = $request->user()->store_id;

    $query = Product::forStore($storeId)
      ->with('category')
      ->when($request->filled('category_id'), function ($query) use ($request) {
        $query->where('category_id', $request->category_id);
      })
      ->when($request->filled('name'), function ($query) use ($request) {
        $query->where('name', 'like', '%' . $request->name . '%');
      })
      ->when($request->filled('sku'), function ($query) use ($request) {
        $query->where('sku', 'like', '%' . $request->sku . '%');
      })
      ->when($request->filled('barcode'), function ($query) use ($request) {
        $query->where('barcode', $request->barcode);
      })
      ->when($request->has('is_active'), function ($query) use ($request) {
        $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
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
   *       @OA\Property(property="stock", type="integer", example=100, nullable=true),
   *       @OA\Property(property="stock_reserved", type="integer", example=0, nullable=true),
   *       @OA\Property(property="stock_min", type="integer", example=10, nullable=true),
   *       @OA\Property(property="is_active", type="boolean", example=true, nullable=true)
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

    $product = Product::create([
      'store_id' => $storeId,
      'name' => $request->name,
      'sku' => $request->sku,
      'category_id' => $request->category_id,
      'barcode' => $request->barcode,
      'description' => $request->description,
      'price' => $request->price,
      'cost' => $request->cost,
      'stock' => $request->stock ?? 0,
      'stock_reserved' => $request->stock_reserved ?? 0,
      'stock_min' => $request->stock_min ?? 0,
      'is_active' => $request->boolean('is_active', true),
    ]);

    $product->load('category');

    return response()->json([
      'status' => 'success',
      'message' => 'Producto creado exitosamente.',
      'data' => ProductResource::make($product),
      'errors' => null,
    ], 201);
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

    $product = Product::forStore($storeId)->with('category')->find($id);

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
   *       @OA\Property(property="stock", type="integer", example=150, nullable=true),
   *       @OA\Property(property="stock_reserved", type="integer", example=10, nullable=true),
   *       @OA\Property(property="stock_min", type="integer", example=15, nullable=true),
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

    $product = Product::forStore($storeId)->find($id);

    if (! $product) {
      return response()->json([
        'status' => 'error',
        'message' => 'Producto no encontrado.',
        'data' => null,
        'errors' => ['id' => ['El producto no existe o no pertenece a tu tienda.']],
      ], 404);
    }

    $product->update($request->validated());
    $product->load('category');

    return response()->json([
      'status' => 'success',
      'message' => 'Producto actualizado exitosamente.',
      'data' => ProductResource::make($product),
      'errors' => null,
    ], 200);
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
