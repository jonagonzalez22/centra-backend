<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\AddStopRequest;
use App\Http\Requests\Api\V1\Store\CancelRouteRequest;
use App\Http\Requests\Api\V1\Store\PlanRouteRequest;
use App\Http\Requests\Api\V1\Store\RemoveStopRequest;
use App\Http\Requests\Api\V1\Store\ReorderStopsRequest;
use App\Http\Requests\Api\V1\Store\RevertRouteRequest;
use App\Http\Requests\Api\V1\Store\StoreRouteRequest;
use App\Http\Requests\Api\V1\Store\UpdateRouteRequest;
use App\Http\Resources\DeliveryRouteResource;
use App\Http\Resources\EligibleOrderResource;
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

        $route = $this->routeService->plan($route, $request->user());

        $route->load(['vehicle', 'driver', 'stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta planificada exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Revert a planned route back to draft.
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

        $route->load(['vehicle', 'driver', 'stops', 'events']);

        return response()->json([
            'status' => 'success',
            'message' => 'Ruta revertida exitosamente.',
            'data' => DeliveryRouteResource::make($route),
            'errors' => null,
        ]);
    }

    /**
     * Cancel a route (draft or planned).
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
}
