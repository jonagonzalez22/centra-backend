<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\AddStopRequest;
use App\Http\Requests\Api\V1\Store\CancelRouteRequest;
use App\Http\Requests\Api\V1\Store\ConfirmLoadRequest;
use App\Http\Requests\Api\V1\Store\DispatchRouteRequest;
use App\Http\Requests\Api\V1\Store\PlanRouteRequest;
use App\Http\Requests\Api\V1\Store\RemoveStopRequest;
use App\Http\Requests\Api\V1\Store\ReorderStopsRequest;
use App\Http\Requests\Api\V1\Store\RevertRouteRequest;
use App\Http\Requests\Api\V1\Store\StoreRouteItemsRequest;
use App\Http\Requests\Api\V1\Store\StoreRouteRequest;
use App\Http\Requests\Api\V1\Store\UpdateRouteRequest;
use App\Http\Resources\DeliveryRouteResource;
use App\Http\Resources\EligibleOrderResource;
use App\Http\Resources\LoadSheetResource;
use App\Http\Resources\RouteStopItemResource;
use App\Http\Resources\RouteStopResource;
use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use App\Services\RouteManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function __construct(
        private readonly RouteManagementService $routeService,
    ) {}

    // ── Query Endpoints ──────────────────────────────────────────────

    /**
     * List eligible orders for route assignment.
     *
     * @OA\Get(
     *   path="/store/routes/eligible-orders",
     *   summary="Listar pedidos elegibles para asignar a una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="requested_delivery_date", in="query", @OA\Schema(type="string", format="date"), description="Filtrar por fecha de entrega solicitada"),
     *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string"), description="Buscar por número de operación o nombre de cliente"),
     *   @OA\Parameter(name="locality_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por localidad"),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Pedidos elegibles obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Pedidos elegibles obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/EligibleOrder")),
     *         @OA\Property(property="total", type="integer", example=30),
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
    public function eligibleOrders(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $orders = $this->routeService->eligibleOrders($storeId, $request->only([
            'requested_delivery_date', 'search', 'locality_id', 'per_page',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Pedidos elegibles obtenidos exitosamente.',
            'data' => [
                'items' => EligibleOrderResource::collection($orders->items()),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * List all routes for the store.
     *
     * @OA\Get(
     *   path="/store/routes",
     *   summary="Listar rutas de la tienda",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1), description="Número de página"),
     *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft", "planned", "cancelled"}), description="Filtrar por estado"),
     *   @OA\Parameter(name="vehicle_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por vehículo"),
     *   @OA\Parameter(name="driver_id", in="query", @OA\Schema(type="string", format="uuid"), description="Filtrar por conductor"),
     *   @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date"), description="Fecha desde (rango)"),
     *   @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date"), description="Fecha hasta (rango)"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Rutas obtenidas exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Rutas obtenidas exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/DeliveryRoute")),
     *         @OA\Property(property="total", type="integer", example=10),
     *         @OA\Property(property="per_page", type="integer", example=15),
     *         @OA\Property(property="current_page", type="integer", example=1),
     *         @OA\Property(property="last_page", type="integer", example=1)
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

        $query = DeliveryRoute::forStore($storeId)
            ->with(['stops.order', 'vehicle', 'driver']);

        if ($request->filled('status')) {
            $query->byStatus($request->get('status'));
        }

        if ($request->filled('vehicle_id')) {
            $query->forVehicle($request->get('vehicle_id'));
        }

        if ($request->filled('driver_id')) {
            $query->forDriver($request->get('driver_id'));
        }

        if ($request->filled('from') || $request->filled('to')) {
            $query->byDateRange($request->get('from'), $request->get('to'));
        }

        $routes = $query->orderBy('operational_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Rutas obtenidas exitosamente.',
            'data' => [
                'items' => DeliveryRouteResource::collection($routes->items()),
                'total' => $routes->total(),
                'per_page' => $routes->perPage(),
                'current_page' => $routes->currentPage(),
                'last_page' => $routes->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Show a single route with stops and events.
     *
     * @OA\Get(
     *   path="/store/routes/{route}",
     *   summary="Obtener una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta obtenida exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta obtenida exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)
            ->with([
                'stops' => fn ($q) => $q->orderBy('sequence'),
                'stops.order.customer.addresses.locality',
                'events' => fn ($q) => $q->orderBy('created_at'),
                'events.user',
                'vehicle',
                'driver',
                'store',
            ])
            ->find($id);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta obtenida exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    // ── CRUD Endpoints ───────────────────────────────────────────────

    /**
     * Create a new delivery route.
     *
     * @OA\Post(
     *   path="/store/routes",
     *   summary="Crear una nueva ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"vehicle_id", "driver_id", "operational_date"},
     *
     *       @OA\Property(property="vehicle_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *       @OA\Property(property="driver_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440001"),
     *       @OA\Property(property="operational_date", type="string", format="date", example="2026-07-30"),
     *       @OA\Property(property="observations", type="string", example="Ruta de reparto zona norte", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Ruta creada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta creada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function store(StoreRouteRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = $this->routeService->create(
            $request->validated(),
            $storeId,
            $request->user()
        );

        $route->load(['vehicle', 'driver', 'stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta creada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ], 201);
    }

    /**
     * Update an existing draft route.
     *
     * @OA\Put(
     *   path="/store/routes/{route}",
     *   summary="Actualizar una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="vehicle_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000", nullable=true),
     *       @OA\Property(property="driver_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440001", nullable=true),
     *       @OA\Property(property="operational_date", type="string", format="date", example="2026-07-30", nullable=true),
     *       @OA\Property(property="observations", type="string", example="Ruta de reparto zona norte", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta actualizada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta actualizada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Ruta no encontrada"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function update(UpdateRouteRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($id);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->update($route, $request->validated());

        $route->load(['vehicle', 'driver', 'stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta actualizada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    // ── Stop Management Endpoints ────────────────────────────────────

    /**
     * Add an order as a stop to a route.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/stops",
     *   summary="Agregar un stop (pedido) a una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"order_id"},
     *
     *       @OA\Property(property="order_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *       @OA\Property(property="reason", type="string", example="Pedido urgente fuera de fecha", nullable=true),
     *       @OA\Property(property="logistics_notes", type="string", example="Entregar en recepción", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Stop agregado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Stop agregado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/RouteStop"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta o pedido no encontrado")
     * )
     */
    public function addStop(AddStopRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $order = CommercialOperation::forStore($storeId)->find($request->order_id);

        if (! $order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pedido no encontrado.',
                'data' => null,
                'errors' => ['order_id' => ['El pedido no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $stop = $this->routeService->addStop($route, $order, $request->validated(), $request->user());

        $stop->load(['order.customer.addresses.locality']);

        return response()->json([
            'status' => 'success',
            'message' => 'Stop agregado exitosamente.',
            'data' => new \App\Http\Resources\RouteStopResource($stop),
            'errors' => null,
        ], 201);
    }

    /**
     * Remove (cancel) a stop from a route.
     *
     * @OA\Delete(
     *   path="/store/routes/{route}/stops/{stop}",
     *   summary="Cancelar un stop de una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *   @OA\Parameter(name="stop", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del stop"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"reason"},
     *
     *       @OA\Property(property="reason", type="string", example="Pedido cancelado por el cliente"),
     *       @OA\Property(property="logistics_notes", type="string", example="Reasignar a otra ruta", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Stop cancelado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Stop cancelado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/RouteStop"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta o stop no encontrado")
     * )
     */
    public function removeStop(RemoveStopRequest $request, string $routeId, string $stopId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $stop = RouteStop::where('id', $stopId)->where('route_id', $routeId)->first();

        if (! $stop) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stop no encontrado.',
                'data' => null,
                'errors' => ['stop_id' => ['El stop no existe o no pertenece a esta ruta.']],
            ], 404);
        }

        $stop = $this->routeService->removeStop($route, $stop, $request->validated(), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Stop cancelado exitosamente.',
            'data' => new \App\Http\Resources\RouteStopResource($stop),
            'errors' => null,
        ]);
    }

    /**
     * Reorder stops in a route.
     *
     * @OA\Put(
     *   path="/store/routes/{route}/stops/reorder",
     *   summary="Reordenar los stops de una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"stop_ids"},
     *
     *       @OA\Property(property="stop_ids", type="array", @OA\Items(type="string", format="uuid"), example={"550e8400-e29b-41d4-a716-446655440000", "550e8400-e29b-41d4-a716-446655440001"})
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Stops reordenados exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Stops reordenados exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function reorderStops(ReorderStopsRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $this->routeService->reorderStops($route, $request->stop_ids, $request->user());

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.order']);

        return response()->json([
            'status' => 'success',
            'message' => 'Stops reordenados exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    // ── Status Transition Endpoints ──────────────────────────────────

    /**
     * Plan (finalize) a draft route.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/plan",
     *   summary="Planificar una ruta (cambia de draft a planned)",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta planificada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta planificada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function plan(PlanRouteRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $departureTime = $request->departure_time ?? $route->departure_time;

        if (! $departureTime) {
            return response()->json([
                'status' => 'error',
                'message' => 'El horario de salida es obligatorio.',
                'data' => null,
                'errors' => ['departure_time' => ['El horario de salida es obligatorio.']],
            ], 422);
        }

        $route = $this->routeService->plan($route, $departureTime, $request->user());

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.order.customer.addresses.locality', 'vehicle', 'driver', 'events', 'store']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta planificada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Revert a planned route back to draft.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/revert",
     *   summary="Revertir una ruta de planned a draft",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"reason"},
     *
     *       @OA\Property(property="reason", type="string", example="Error en la asignación del vehículo"),
     *       @OA\Property(property="observation", type="string", example="Se debe cambiar el vehículo por uno con más capacidad", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta revertida exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta revertida exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function revert(RevertRouteRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->revert(
            $route,
            $request->reason,
            $request->observation,
            $request->user()
        );

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.order.customer.addresses.locality', 'vehicle', 'driver', 'events', 'store']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta revertida exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Cancel a route (draft or planned).
     *
     * @OA\Post(
     *   path="/store/routes/{route}/cancel",
     *   summary="Cancelar una ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"reason"},
     *
     *       @OA\Property(property="reason", type="string", example="Condiciones climáticas adversas")
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta cancelada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta cancelada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function cancel(CancelRouteRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->cancel($route, $request->reason, $request->user());

        $route->load(['vehicle', 'driver', 'stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta cancelada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Recalculate a planned route that requires recalculation after stop reordering.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/recalculate",
     *   summary="Recalcular una ruta planificada después de reordenar stops",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta recalculada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta recalculada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function recalculate(Request $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->recalculate($route, $request->user());

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.order.customer.addresses.locality', 'vehicle', 'driver', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta recalculada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    // ── Execution Endpoints ──────────────────────────────────────────

    /**
     * Assign items (products) to a stop.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/stops/{stop}/items",
     *   summary="Asignar items a un stop de la ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *   @OA\Parameter(name="stop", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del stop"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"items"},
     *       @OA\Property(
     *         property="items",
     *         type="array",
     *         @OA\Items(
     *           required={"product_id", "quantity_planned"},
     *           @OA\Property(property="product_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *           @OA\Property(property="quantity_planned", type="integer", example=5)
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Items asignados exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Items asignados exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/RouteStop"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta o stop no encontrado")
     * )
     */
    public function assignItems(StoreRouteItemsRequest $request, string $routeId, string $stopId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $stop = RouteStop::where('id', $stopId)->where('route_id', $routeId)->first();

        if (! $stop) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stop no encontrado.',
                'data' => null,
                'errors' => ['stop_id' => ['El stop no existe o no pertenece a esta ruta.']],
            ], 404);
        }

        $this->routeService->assignItems($route, $stop, $request->input('items'), $request->user());

        $stop->load(['items.product']);

        return response()->json([
            'status' => 'success',
            'message' => 'Items asignados exitosamente.',
            'data' => RouteStopResource::make($stop),
            'errors' => null,
        ]);
    }

    /**
     * Get consolidated load sheet for warehouse picking.
     *
     * @OA\Get(
     *   path="/store/routes/{route}/load-sheet",
     *   summary="Obtener hoja de carga consolidada para picking",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Hoja de carga obtenida exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Hoja de carga obtenida exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/LoadSheet"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function loadSheet(Request $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->with(['stops' => fn ($q) => $q->orderBy('sequence')])->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $loadSheet = $this->routeService->getLoadSheet($route);

        return response()->json([
            'status' => 'success',
            'message' => 'Hoja de carga obtenida exitosamente.',
            'data' => LoadSheetResource::make($loadSheet),
            'errors' => null,
        ]);
    }

    /**
     * Confirm load: transition route from planned to loaded.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/confirm-load",
     *   summary="Confirmar carga de la ruta",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"items"},
     *       @OA\Property(
     *         property="items",
     *         type="array",
     *         @OA\Items(
     *           required={"route_stop_item_id", "quantity_loaded"},
     *           @OA\Property(property="route_stop_item_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
     *           @OA\Property(property="quantity_loaded", type="integer", example=5),
     *           @OA\Property(property="reason", type="string", example="Rotura de mercadería", nullable=true)
     *         )
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Carga confirmada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Carga confirmada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function confirmLoad(ConfirmLoadRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->confirmLoad($route, $request->input('items'), $request->user());

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.items.product', 'vehicle', 'driver', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Carga confirmada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Dispatch a loaded route.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/dispatch",
     *   summary="Despachar una ruta cargada",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Ruta despachada exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Ruta despachada exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function dispatch(DispatchRouteRequest $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->dispatch($route, $request->user());

        $route->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'stops.items.product', 'vehicle', 'driver', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta despachada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Process deliveries: reconcile completed/failed stops, update inventory,
     * release stock_reserved, update order statuses, and complete the route.
     *
     * @OA\Post(
     *   path="/store/routes/{route}/process-deliveries",
     *   summary="Procesar entregas de una ruta (reconciliación)",
     *   tags={"Store - Rutas"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID de la ruta"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Entregas procesadas exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Entregas procesadas exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/DeliveryRoute"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=409, description="Ruta ya procesada"),
     *   @OA\Response(response=422, description="Error de validación"),
     *   @OA\Response(response=404, description="Ruta no encontrada")
     * )
     */
    public function processDeliveries(Request $request, string $routeId): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $route = DeliveryRoute::forStore($storeId)->find($routeId);

        if (! $route) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ruta no encontrada.',
                'data' => null,
                'errors' => ['id' => ['La ruta no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $route = $this->routeService->processDeliveries($route, $request->user());

        $route->load(['stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Entregas procesadas exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }
}
