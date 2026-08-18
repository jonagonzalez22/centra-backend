<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverStopResource;
use App\Models\RouteStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver-only read endpoints for individual stops.
 * All endpoints are lectura-only: no stock changes, no InventoryMovements created.
 *
 * @group Driver
 */
class DriverStopController extends Controller
{
    /**
     * Get full detail of a single stop.
     *
     * Returns complete stop data including items and collections.
     * Actor-scope: the driver must be assigned to the stop's route.
     *
     * @OA\Get(
     *     path="/driver/stops/{stop_id}",
     *     summary="Obtener detalle de una parada",
     *     tags={"Driver"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="stop_id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Detalle de la parada",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="string", format="uuid"),
     *                 @OA\Property(property="route_id", type="string", format="uuid"),
     *                 @OA\Property(property="sequence", type="integer"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="address", type="string", nullable=true),
     *                 @OA\Property(property="contact_name", type="string", nullable=true),
     *                 @OA\Property(property="contact_phone", type="string", nullable=true),
     *                 @OA\Property(property="timezone", type="string"),
     *                 @OA\Property(property="eta", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="notification_window_start", type="string"),
     *                 @OA\Property(property="notification_window_end", type="string"),
     *                 @OA\Property(property="notes", type="string", nullable=true),
     *                 @OA\Property(property="items", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="string", format="uuid"),
     *                     @OA\Property(property="product_id", type="string", format="uuid"),
     *                     @OA\Property(property="product_name", type="string"),
     *                     @OA\Property(property="sku", type="string", nullable=true),
     *                     @OA\Property(property="quantity_planned", type="integer"),
     *                     @OA\Property(property="quantity_loaded", type="integer"),
     *                     @OA\Property(property="quantity_delivered", type="integer"),
     *                     @OA\Property(property="original_route_stop_id", type="string", format="uuid", nullable=true),
     *                     @OA\Property(property="is_extra", type="boolean"),
     *                     @OA\Property(property="notes", type="string", nullable=true)
     *                 )),
     *                 @OA\Property(property="collections", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="string", format="uuid"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="amount", type="number"),
     *                     @OA\Property(property="method", type="string", nullable=true),
     *                     @OA\Property(property="declared_at", type="string", format="date-time", nullable=true)
     *                 ))
     *             ),
     *             @OA\Property(property="errors", type="null")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Stop not assigned to this driver"),
     *     @OA\Response(response=404, description="Stop not found")
     * )
     */
    public function show(Request $request, string $stopId): JsonResponse
    {
        $driver = $request->user();

        $stop = RouteStop::with([
            'order.customer.addresses',
            'order.customer.contacts',
            'items.product',
            'collections.storePaymentMethod.paymentMethod',
            'route',
        ])->find($stopId);

        if (! $stop) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parada no encontrada.',
                'data' => null,
                'errors' => ['error' => ['Parada no encontrada.']],
            ], 404);
        }

        // Actor-scope: verify driver owns this stop's route
        if ($stop->route->driver_id !== $driver->id || $stop->route->store_id !== $driver->store_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parada no asignada a este conductor.',
                'data' => null,
                'errors' => ['error' => ['Parada no asignada a este conductor.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => DriverStopResource::make($stop),
            'errors' => null,
        ]);
    }
}
