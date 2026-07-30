<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Driver\CompleteStopRequest;
use App\Http\Resources\DeliveryRouteResource;
use App\Http\Resources\RouteStopResource;
use App\Models\RouteStop;
use App\Services\DriverExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverExecutionController extends Controller
{
    public function __construct(
        private readonly DriverExecutionService $executionService,
    ) {}

    /**
     * Get the active (dispatched) route for the authenticated driver.
     *
     * @OA\Get(
     *   path="/driver/active-route",
     *   summary="Obtener ruta activa del conductor",
     *   tags={"Driver"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta activa obtenida exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="null", example=null),
     *       @OA\Property(property="data", ref="#/components/schemas/DriverActiveRoute"),
     *       @OA\Property(property="errors", type="null", example=null),
     *       @OA\Property(property="meta", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=404,
     *     description="No se encontró una ruta despachada para este conductor.",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="error"),
     *       @OA\Property(property="message", type="string", example="No se encontró una ruta activa para este conductor."),
     *       @OA\Property(property="data", type="null", example=null),
     *       @OA\Property(property="errors", type="object", example={"error": {"No se encontró una ruta despachada para este conductor."}})
     *     )
     *   )
     * )
     */
    public function activeRoute(Request $request): JsonResponse
    {
        $driver = $request->user();
        $route = $this->executionService->getActiveRoute($driver);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró una ruta activa para este conductor.',
                'data' => null,
                'errors' => ['error' => ['No se encontró una ruta despachada para este conductor.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => null,
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
            'meta' => null,
        ]);
    }

    /**
     * Mark arrival at a stop, optionally recording GPS coordinates.
     *
     * @OA\Post(
     *   path="/driver/stops/{stop}/arrive",
     *   summary="Registrar llegada a una parada",
     *   tags={"Driver"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="stop", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del RouteStop"),
     *
     *   @OA\RequestBody(
     *     required=false,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="gps_lat", type="number", example=-34.6037, nullable=true),
     *       @OA\Property(property="gps_lon", type="number", example=-58.3816, nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Llegada registrada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="null", example=null),
     *       @OA\Property(property="data", ref="#/components/schemas/RouteStop"),
     *       @OA\Property(property="errors", type="null", example=null),
     *       @OA\Property(property="meta", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="Conductor no asignado a esta parada"),
     *   @OA\Response(response=404, description="Parada no encontrada")
     * )
     */
    public function arrive(Request $request, string $stopId): JsonResponse
    {
        $driver = $request->user();
        $stop = RouteStop::findOrFail($stopId);

        $stop = $this->executionService->arriveStop($stop, $request->only(['gps_lat', 'gps_lon']), $driver);

        return response()->json([
            'status' => 'success',
            'message' => null,
            'data' => RouteStopResource::make($stop),
            'errors' => null,
            'meta' => null,
        ]);
    }

    /**
     * Complete a stop delivery with item-level quantities.
     *
     * @OA\Post(
     *   path="/driver/stops/{stop}/complete",
     *   summary="Completar entrega de una parada",
     *   tags={"Driver"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="stop", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del RouteStop"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"status", "items"},
     *
     *       @OA\Property(property="status", type="string", enum={"completed", "failed"}, example="completed"),
     *       @OA\Property(property="gps_lat", type="number", example=-34.6037, nullable=true),
     *       @OA\Property(property="gps_lon", type="number", example=-58.3816, nullable=true),
     *       @OA\Property(property="rejection_reason_id", type="string", format="uuid", nullable=true, description="Requerido si status=failed"),
     *       @OA\Property(
     *         property="items",
     *         type="array",
     *
     *         @OA\Items(
     *           required={"route_stop_item_id", "quantity_delivered"},
     *
     *           @OA\Property(property="route_stop_item_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *           @OA\Property(property="quantity_delivered", type="integer", example=5),
     *           @OA\Property(property="rejection_reason_id", type="string", format="uuid", nullable=true)
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Entrega completada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="null", example=null),
     *       @OA\Property(property="data", ref="#/components/schemas/RouteStop"),
     *       @OA\Property(property="errors", type="null", example=null),
     *       @OA\Property(property="meta", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="Conductor no asignado a esta parada"),
     *   @OA\Response(response=404, description="Parada no encontrada"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function complete(CompleteStopRequest $request, string $stopId): JsonResponse
    {
        $driver = $request->user();
        $stop = RouteStop::findOrFail($stopId);

        $stop = $this->executionService->completeStop($stop, $request->validated(), $driver);

        return response()->json([
            'status' => 'success',
            'message' => null,
            'data' => RouteStopResource::make($stop),
            'errors' => null,
            'meta' => null,
        ]);
    }
}
