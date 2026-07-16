<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\ListCommercialOperationsRequest;
use App\Http\Resources\CommercialOperationResource;
use App\Models\CommercialOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialOperationController extends Controller
{
    /**
     * Display a listing of commercial operations for the authenticated user's store.
     *
     * @OA\Get(
     *   path="/store/operations",
     *   summary="Listar operaciones comerciales de la tienda",
     *   tags={"Store - Operaciones Comerciales"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"sale", "order"}), description="Filtrar por tipo de operación"),
     *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending", "confirmed", "completed", "cancelled"}), description="Filtrar por estado"),
     *   @OA\Parameter(name="customer_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por cliente"),
     *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date"), description="Fecha de inicio (YYYY-MM-DD)"),
     *   @OA\Parameter(name="date_to", in="query", @OA\Schema(type="string", format="date"), description="Fecha de fin (YYYY-MM-DD)"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Operaciones comerciales obtenidas exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Operaciones comerciales obtenidas exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/CommercialOperationResource")),
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
    public function index(ListCommercialOperationsRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $query = CommercialOperation::forStore($storeId)
            ->with(['customer', 'user', 'items', 'payments.storePaymentMethod'])
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->byType($request->type);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->byStatus($request->status);
            })
            ->when($request->filled('customer_id'), function ($query) use ($request) {
                $query->forCustomer($request->customer_id);
            })
            ->when($request->filled('date_from') || $request->filled('date_to'), function ($query) use ($request) {
                $query->betweenDates($request->date_from, $request->date_to);
            })
            ->orderBy('created_at', 'desc');

        $operations = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Operaciones comerciales obtenidas exitosamente.',
            'data' => [
                'items' => CommercialOperationResource::collection($operations->items()),
                'total' => $operations->total(),
                'per_page' => $operations->perPage(),
                'current_page' => $operations->currentPage(),
                'last_page' => $operations->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Display the specified commercial operation.
     *
     * @OA\Get(
     *   path="/store/operations/{id}",
     *   summary="Ver una operación comercial específica",
     *   tags={"Store - Operaciones Comerciales"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Operación comercial obtenida exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Operación comercial obtenida exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/CommercialOperationResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Operación comercial no encontrada")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $operation = CommercialOperation::forStore($storeId)
            ->with(['customer', 'user', 'items.product', 'payments.storePaymentMethod.paymentMethod'])
            ->find($id);

        if (! $operation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Operación comercial no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La operación comercial no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Operación comercial obtenida exitosamente.',
            'data' => CommercialOperationResource::make($operation),
            'errors' => null,
        ], 200);
    }
}
