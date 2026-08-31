<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Driver\AddExtraSaleRequest;
use App\Models\RouteStop;
use App\Services\DriverExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverExtraSaleController extends Controller
{
    public function __construct(
        private DriverExecutionService $executionService
    ) {}

    /**
     * Get available surplus products on the route from completed/failed stops.
     *
     * @OA\Get(
     *   path="/driver/routes/{route}/available-surplus",
     *   summary="Obtener excedentes disponibles en la ruta",
     *   tags={"Driver"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Excedentes disponibles obtenidos correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Excedentes disponibles obtenidos correctamente."),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         @OA\Property(property="route_id", type="string", format="uuid"),
     *         @OA\Property(
     *           property="surplus",
     *           type="array",
     *
     *           @OA\Items(ref="#/components/schemas/AvailableSurplus")
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Ruta no encontrada o no está en estado despachado")
     * )
     */
    public function availableSurplus(Request $request, string $routeId): JsonResponse
    {
        $driver = $request->user();

        $surplus = $this->executionService->getAvailableSurplus($routeId, $driver);

        return response()->json([
            'status' => 'success',
            'message' => 'Excedentes disponibles obtenidos correctamente.',
            'data' => [
                'route_id' => $routeId,
                'surplus' => $surplus,
            ],
        ]);
    }

    /**
     * Add extra sale items to a pending/arrived stop.
     *
     * @OA\Post(
     *   path="/driver/stops/{stop}/extra-sales",
     *   summary="Agregar venta extra a una parada",
     *   tags={"Driver"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="stop", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del RouteStop"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"items"},
     *
     *       @OA\Property(
     *         property="items",
     *         type="array",
     *
     *         @OA\Items(
     *           required={"product_id", "quantity"},
     *
     *           @OA\Property(property="product_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *           @OA\Property(property="quantity", type="integer", minimum=1, example=3)
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Venta extra agregada correctamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Venta extra agregada correctamente."),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         @OA\Property(
     *           property="stop",
     *           type="object",
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="route_id", type="string", format="uuid"),
     *           @OA\Property(property="sequence", type="integer"),
     *           @OA\Property(property="status", type="string"),
     *           @OA\Property(
     *             property="order",
     *             type="object",
     *             nullable=true,
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="operation_number", type="string"),
     *             @OA\Property(property="total", type="number"),
     *             @OA\Property(property="pending_balance", type="number")
     *           ),
     *           @OA\Property(
     *             property="items",
     *             type="array",
     *
     *             @OA\Items(ref="#/components/schemas/ExtraSaleItem")
     *           )
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Parada o ruta no encontrada"),
     *   @OA\Response(response=422, description="Error de validación - cantidad excede disponible o stop no está en estado válido")
     * )
     */
    public function addExtraSale(AddExtraSaleRequest $request, string $stopId): JsonResponse
    {
        $driver = $request->user();
        $items = $request->validated()['items'];

        $stop = $this->executionService->addExtraSale($stopId, $items, $driver);

        return response()->json([
            'status' => 'success',
            'message' => 'Venta extra agregada correctamente.',
            'data' => [
                'stop' => [
                    'id' => $stop->id,
                    'route_id' => $stop->route_id,
                    'sequence' => $stop->sequence,
                    'status' => $stop->status,
                    'order' => $stop->order ? [
                        'id' => $stop->order->id,
                        'operation_number' => $stop->order->operation_number,
                        'total' => (float) $stop->order->total,
                        'pending_balance' => $stop->order->pending_balance,
                    ] : null,
                    'items' => $stop->items->map(fn ($item) => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'quantity_planned' => $item->quantity_planned,
                        'quantity_loaded' => $item->quantity_loaded,
                        'quantity_delivered' => $item->quantity_delivered,
                        'is_extra' => $item->is_extra,
                    ]),
                ],
            ],
        ]);
    }
}
