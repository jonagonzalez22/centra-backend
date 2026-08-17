<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverStopSummaryResource;
use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver-only read endpoints for routes and stops.
 * All endpoints are lectura-only: no stock changes, no InventoryMovements created.
 *
 * @group Driver
 */
class DriverRouteController extends Controller
{
    /**
     * List all stops for a route (summary view).
     *
     * Returns a lightweight list of stops for the authenticated driver.
     * Actor-scope: the driver must be assigned to the route.
     *
     * @OA\Get(
     *     path="/driver/routes/{route_id}/stops",
     *     summary="Listar paradas de la ruta del conductor",
     *     tags={"Driver"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="route_id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Lista de paradas",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="string", format="uuid"),
     *                 @OA\Property(property="sequence", type="integer"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="address", type="string", nullable=true),
     *                 @OA\Property(property="contact_name", type="string", nullable=true),
     *                 @OA\Property(property="contact_phone", type="string", nullable=true),
     *                 @OA\Property(property="notification_window_start", type="string"),
     *                 @OA\Property(property="notification_window_end", type="string"),
     *                 @OA\Property(property="items_count", type="integer"),
     *                 @OA\Property(property="total_planned_items", type="integer")
     *             )),
     *             @OA\Property(property="errors", type="null")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Driver not assigned to this route"),
     *     @OA\Response(response=404, description="Route not found")
     * )
     */
    public function stops(Request $request, string $routeId): JsonResponse
    {
        $driver = $request->user();

        $route = DeliveryRoute::where('id', $routeId)
            ->where('driver_id', $driver->id)
            ->where('store_id', $driver->store_id)
            ->first();

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada o no asignada a este conductor.',
                'data' => null,
                'errors' => ['error' => ['Ruta no encontrada o no asignada a este conductor.']],
            ], 404);
        }

        $stops = RouteStop::where('route_id', $route->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('sequence')
            ->with([
                'order.customer.addresses',
                'order.customer.contacts',
                'items',
            ])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => DriverStopSummaryResource::collection($stops),
            'errors' => null,
        ]);
    }
}
