<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\AdjustInventoryRequest;
use App\Http\Requests\Api\V1\Store\ListInventoryMovementsRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryMovementService $movementService
    ) {}

    /**
     * Display a listing of inventory movements for the authenticated user's store.
     *
     * @OA\Get(
     *   path="/store/inventory/movements",
     *   summary="Listar movimientos de inventario",
     *   tags={"Store - Inventario"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="product_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por producto"),
     *   @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"input", "output", "adjustment"}), description="Filtrar por tipo"),
     *   @OA\Parameter(name="user_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por usuario"),
     *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date"), description="Fecha de inicio (YYYY-MM-DD)"),
     *   @OA\Parameter(name="date_to", in="query", @OA\Schema(type="string", format="date"), description="Fecha de fin (YYYY-MM-DD)"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Movimientos obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Movimientos de inventario obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/InventoryMovementResource")),
     *         @OA\Property(property="total", type="integer", example=50),
     *         @OA\Property(property="per_page", type="integer", example=15),
     *         @OA\Property(property="current_page", type="integer", example=1),
     *         @OA\Property(property="last_page", type="integer", example=4)
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function index(ListInventoryMovementsRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $query = InventoryMovement::forStore($storeId)
            ->with(['product', 'user'])
            ->when($request->filled('product_id'), function ($query) use ($request) {
                $query->where('product_id', $request->product_id);
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->when($request->filled('user_id'), function ($query) use ($request) {
                $query->where('user_id', $request->user_id);
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->orderBy('created_at', 'desc');

        $movements = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Movimientos de inventario obtenidos exitosamente.',
            'data' => [
                'items' => InventoryMovementResource::collection($movements->items()),
                'total' => $movements->total(),
                'per_page' => $movements->perPage(),
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Perform a manual inventory adjustment.
     *
     * @OA\Post(
     *   path="/store/inventory/adjust",
     *   summary="Realizar ajuste manual de stock",
     *   tags={"Store - Inventario"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"product_id", "quantity", "type", "concept"},
     *
     *       @OA\Property(property="product_id", type="string", format="uuid", example="uuid-producto"),
     *       @OA\Property(property="quantity", type="integer", example=10),
     *       @OA\Property(property="type", type="string", enum={"input", "output", "adjustment"}, example="input"),
     *       @OA\Property(property="concept", type="string", maxLength=255, example="Ajuste por carga inicial")
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Ajuste realizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ajuste de inventario realizado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/InventoryMovementResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=400, description="Stock insuficiente o validación de negocio"),
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=404, description="Producto no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function adjust(AdjustInventoryRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $product = Product::forStore($storeId)->find($request->product_id);

        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Producto no encontrado.',
                'data' => null,
                'errors' => ['product_id' => ['El producto no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        try {
            $movement = $this->movementService->recordMovement(
                product: $product,
                user: $request->user(),
                type: $request->type,
                quantity: (int) $request->quantity,
                concept: $request->concept
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Ajuste de inventario realizado exitosamente.',
                'data' => InventoryMovementResource::make($movement),
                'errors' => null,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['quantity' => [$e->getMessage()]],
            ], 422);
        }
    }
}
