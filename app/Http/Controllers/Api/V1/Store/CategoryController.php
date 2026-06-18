<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Store\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
  /**
   * Display a listing of categories for the authenticated user's store.
   *
   * @OA\Get(
   *   path="/store/categories",
   *   summary="Listar categorías de la tienda",
   *   tags={"Store - Categorías"},
   *   security={{"sanctum":{}}},
   *
   *   @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean"), description="Filtrar por estado activo"),
   *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
   *
   *   @OA\Response(
   *     response=200,
   *     description="Categorías obtenidas exitosamente",
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="status", type="string", example="success"),
   *       @OA\Property(property="message", type="string", example="Categorías obtenidas exitosamente."),
   *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CategoryResource")),
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

    $categories = Category::forStore($storeId)
      ->when($request->has('is_active'), function ($query) use ($request) {
        $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
      })
      ->orderBy('name')
      ->paginate($request->get('per_page', 15));

    return response()->json([
      'status' => 'success',
      'message' => 'Categorías obtenidas exitosamente.',
      'data' => CategoryResource::collection($categories),
      'errors' => null,
    ], 200)->withHeaders([
      'X-Pagination-Total' => $categories->total(),
      'X-Pagination-Current-Page' => $categories->currentPage(),
      'X-Pagination-Last-Page' => $categories->lastPage(),
      'X-Pagination-Per-Page' => $categories->perPage(),
    ]);
  }

  /**
   * Store a newly created category.
   *
   * @OA\Post(
   *   path="/store/categories",
   *   summary="Crear una nueva categoría",
   *   tags={"Store - Categorías"},
   *   security={{"sanctum":{}}},
   *
   *   @OA\RequestBody(
   *     required=true,
   *
   *     @OA\JsonContent(
   *       required={"name"},
   *
   *       @OA\Property(property="name", type="string", maxLength=100, example="Electrónica"),
   *       @OA\Property(property="description", type="string", maxLength=500, example="Productos electrónicos y gadgets", nullable=true),
   *       @OA\Property(property="is_active", type="boolean", example=true)
   *     )
   *   ),
   *
   *   @OA\Response(
   *     response=201,
   *     description="Categoría creada exitosamente",
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="status", type="string", example="success"),
   *       @OA\Property(property="message", type="string", example="Categoría creada exitosamente."),
   *       @OA\Property(property="data", ref="#/components/schemas/CategoryResource"),
   *       @OA\Property(property="errors", type="null", example=null)
   *     )
   *   ),
   *
   *   @OA\Response(response=422, description="Error de validación")
   * )
   */
  public function store(StoreCategoryRequest $request): JsonResponse
  {
    $storeId = $request->user()->store_id;

    $category = Category::create([
      'store_id' => $storeId,
      'name' => $request->name,
      'description' => $request->description,
      'is_active' => $request->boolean('is_active', true),
    ]);

    $category->load('store');

    return response()->json([
      'status' => 'success',
      'message' => 'Categoría creada exitosamente.',
      'data' => CategoryResource::make($category),
      'errors' => null,
    ], 201);
  }

  /**
   * Display the specified category.
   *
   * @OA\Get(
   *   path="/store/categories/{id}",
   *   summary="Ver una categoría específica",
   *   tags={"Store - Categorías"},
   *   security={{"sanctum":{}}},
   *
   *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
   *
   *   @OA\Response(
   *     response=200,
   *     description="Categoría obtenida exitosamente",
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="status", type="string", example="success"),
   *       @OA\Property(property="message", type="string", example="Categoría obtenida exitosamente."),
   *       @OA\Property(property="data", ref="#/components/schemas/CategoryResource"),
   *       @OA\Property(property="errors", type="null", example=null)
   *     )
   *   ),
   *
   *   @OA\Response(response=404, description="Categoría no encontrada")
   * )
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $storeId = $request->user()->store_id;

    $category = Category::forStore($storeId)->find($id);

    if (! $category) {
      return response()->json([
        'status' => 'error',
        'message' => 'Categoría no encontrada.',
        'data' => null,
        'errors' => ['id' => ['La categoría no existe o no pertenece a tu tienda.']],
      ], 404);
    }

    $category->load('store');

    return response()->json([
      'status' => 'success',
      'message' => 'Categoría obtenida exitosamente.',
      'data' => CategoryResource::make($category),
      'errors' => null,
    ], 200);
  }

  /**
   * Update the specified category.
   *
   * @OA\Put(
   *   path="/store/categories/{id}",
   *   summary="Actualizar una categoría",
   *   tags={"Store - Categorías"},
   *   security={{"sanctum":{}}},
   *
   *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
   *
   *   @OA\RequestBody(
   *     required=true,
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="name", type="string", maxLength=100, example="Electrónica Actualizada", nullable=true),
   *       @OA\Property(property="description", type="string", maxLength=500, example="Descripción actualizada", nullable=true),
   *       @OA\Property(property="is_active", type="boolean", example=false)
   *     )
   *   ),
   *
   *   @OA\Response(
   *     response=200,
   *     description="Categoría actualizada exitosamente",
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="status", type="string", example="success"),
   *       @OA\Property(property="message", type="string", example="Categoría actualizada exitosamente."),
   *       @OA\Property(property="data", ref="#/components/schemas/CategoryResource"),
   *       @OA\Property(property="errors", type="null", example=null)
   *     )
   *   ),
   *
   *   @OA\Response(response=404, description="Categoría no encontrada"),
   *   @OA\Response(response=422, description="Error de validación")
   * )
   */
  public function update(UpdateCategoryRequest $request, string $id): JsonResponse
  {
    $storeId = $request->user()->store_id;

    $category = Category::forStore($storeId)->find($id);

    if (! $category) {
      return response()->json([
        'status' => 'error',
        'message' => 'Categoría no encontrada.',
        'data' => null,
        'errors' => ['id' => ['La categoría no existe o no pertenece a tu tienda.']],
      ], 404);
    }

    $category->update($request->validated());

    $category->load('store');

    return response()->json([
      'status' => 'success',
      'message' => 'Categoría actualizada exitosamente.',
      'data' => CategoryResource::make($category),
      'errors' => null,
    ], 200);
  }

  /**
   * Remove the specified category.
   *
   * @OA\Delete(
   *   path="/store/categories/{id}",
   *   summary="Eliminar una categoría",
   *   tags={"Store - Categorías"},
   *   security={{"sanctum":{}}},
   *
   *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
   *
   *   @OA\Response(
   *     response=200,
   *     description="Categoría eliminada exitosamente",
   *
   *     @OA\JsonContent(
   *
   *       @OA\Property(property="status", type="string", example="success"),
   *       @OA\Property(property="message", type="string", example="Categoría eliminada exitosamente."),
   *       @OA\Property(property="data", type="null", example=null),
   *       @OA\Property(property="errors", type="null", example=null)
   *     )
   *   ),
   *
   *   @OA\Response(response=404, description="Categoría no encontrada"),
   *   @OA\Response(response=409, description="La categoría tiene productos asociados")
   * )
   */
  public function destroy(Request $request, string $id): JsonResponse
  {
    $storeId = $request->user()->store_id;

    $category = Category::forStore($storeId)->find($id);

    if (! $category) {
      return response()->json([
        'status' => 'error',
        'message' => 'Categoría no encontrada.',
        'data' => null,
        'errors' => ['id' => ['La categoría no existe o no pertenece a tu tienda.']],
      ], 404);
    }

    if (method_exists($category, 'products') && $category->products()->exists()) {
      return response()->json([
        'status' => 'error',
        'message' => 'No se puede eliminar la categoría porque tiene productos asociados.',
        'data' => null,
        'errors' => ['category' => ['La categoría tiene productos asociados. Elimine o reasigne los productos primero.']],
      ], 409);
    }

    $category->delete();

    return response()->json([
      'status' => 'success',
      'message' => 'Categoría eliminada exitosamente.',
      'data' => null,
      'errors' => null,
    ], 200);
  }
}
