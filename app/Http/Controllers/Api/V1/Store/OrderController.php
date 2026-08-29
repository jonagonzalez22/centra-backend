<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\ListOrdersRequest;
use App\Http\Resources\CommercialOperationListResource;
use App\Http\Resources\CommercialOperationResource;
use App\Models\CommercialOperation;
use App\Services\OrderHistoryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Listado paginado de pedidos de la tienda.
     *
     * @OA\Get(
     *   path="/store/orders",
     *   summary="Listar pedidos de la tienda",
     *   tags={"Store - Pedidos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="date", in="query", @OA\Schema(type="string", format="date"), description="Filtrar por fecha de entrega (YYYY-MM-DD). Ignora date_from y date_to."),
     *   @OA\Parameter(name="date_from", in="query", @OA\Schema(type="string", format="date"), description="Filtrar desde fecha (YYYY-MM-DD)"),
     *   @OA\Parameter(name="date_to", in="query", @OA\Schema(type="string", format="date"), description="Filtrar hasta fecha (YYYY-MM-DD)"),
     *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"open", "confirmed", "cancelled", "closed"}), description="Filtrar por estado. Default: activos (open, confirmed)"),
     *   @OA\Parameter(name="operation_number", in="query", @OA\Schema(type="string"), description="Búsqueda por número de pedido (parcial)"),
     *   @OA\Parameter(name="customer_name", in="query", @OA\Schema(type="string"), description="Búsqueda por nombre de cliente"),
     *   @OA\Parameter(name="locality", in="query", @OA\Schema(type="string"), description="Búsqueda por localidad del domicilio de entrega"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Pedidos obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Pedidos obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/CommercialOperationListResource")),
     *         @OA\Property(property="total", type="integer", example=25),
     *         @OA\Property(property="per_page", type="integer", example=20),
     *         @OA\Property(property="current_page", type="integer", example=1),
     *         @OA\Property(property="last_page", type="integer", example=2)
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="Sin permisos")
     * )
     */
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $query = CommercialOperation::forStore($storeId)
            ->byType('order')
            ->with(['customer.addresses.locality', 'items', 'payments', 'routeStops']);

        // Status filter: applied only when explicitly provided
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        // Filtros de fecha
        if ($request->filled('date')) {
            $query->whereDate('requested_delivery_date', $request->date);
        } else {
            if ($request->filled('date_from')) {
                $query->whereDate('requested_delivery_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('requested_delivery_date', '<=', $request->date_to);
            }
        }

        // Búsqueda por número de pedido
        if ($request->filled('operation_number')) {
            $query->where('operation_number', 'like', '%'.$request->operation_number.'%');
        }

        // Búsqueda por nombre de cliente
        if ($request->filled('customer_name')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('display_name', 'like', '%'.$request->customer_name.'%');
            });
        }

        // Búsqueda por localidad del domicilio de entrega
        if ($request->filled('locality')) {
            $query->whereHas('customer.addresses', function ($q) use ($request) {
                $q->where('is_main', true)
                    ->whereHas('locality', function ($q2) use ($request) {
                        $q2->where('name', 'like', '%'.$request->locality.'%');
                    });
            });
        }

        $query->orderBy('requested_delivery_date', 'asc')
            ->orderBy('operation_number', 'asc');

        $query->withSum('payments', 'amount');

        $operations = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'status' => 'success',
            'message' => 'Pedidos obtenidos exitosamente.',
            'data' => [
                'items' => CommercialOperationListResource::collection($operations->items()),
                'total' => $operations->total(),
                'per_page' => $operations->perPage(),
                'current_page' => $operations->currentPage(),
                'last_page' => $operations->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Detalle completo de un pedido.
     *
     * @OA\Get(
     *   path="/store/orders/{id}",
     *   summary="Ver detalle de un pedido",
     *   tags={"Store - Pedidos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Pedido obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Pedido obtenido exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/CommercialOperationResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="Sin permisos"),
     *   @OA\Response(response=404, description="Pedido no encontrado")
     * )
     */
    public function show(Request $request, string $id, OrderHistoryBuilder $historyBuilder): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $operation = CommercialOperation::query()
            ->forStore($storeId)
            ->with([
                'customer.addresses.locality',
                'items.product',
                'payments.storePaymentMethod.paymentMethod',
                'user',
            ])
            ->withSum('payments', 'amount')
            ->find($id);

        if (! $operation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pedido no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El pedido no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        if ($operation->type !== 'order') {
            return response()->json([
                'status' => 'error',
                'message' => 'Pedido no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El recurso solicitado no es un pedido.']],
            ], 404);
        }

        $historyBuilder->attach($operation, $storeId);

        return response()->json([
            'status' => 'success',
            'message' => 'Pedido obtenido exitosamente.',
            'data' => CommercialOperationResource::make($operation),
            'errors' => null,
        ], 200);
    }
}
