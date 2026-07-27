<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreVehicleRequest;
use App\Http\Requests\Api\V1\Store\ToggleVehicleActiveRequest;
use App\Http\Requests\Api\V1\Store\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /**
     * Display a listing of vehicles for the authenticated user's store.
     *
     * @OA\Get(
     *   path="/store/vehicles",
     *   summary="Listar vehículos de la tienda",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Vehículos obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículos obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/VehicleResource")),
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
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicles = Vehicle::forStore($storeId)
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículos obtenidos exitosamente.',
            'data' => [
                'items' => VehicleResource::collection($vehicles->items()),
                'total' => $vehicles->total(),
                'per_page' => $vehicles->perPage(),
                'current_page' => $vehicles->currentPage(),
                'last_page' => $vehicles->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Store a newly created vehicle.
     *
     * @OA\Post(
     *   path="/store/vehicles",
     *   summary="Crear un vehículo",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"name", "plate", "type"},
     *
     *       @OA\Property(property="name", type="string", example="Furgoneta Blanca"),
     *       @OA\Property(property="plate", type="string", example="ABC-1234"),
     *       @OA\Property(property="type", type="string", enum={"auto", "moto", "bicicleta", "camioneta", "camion"}, example="camioneta"),
     *       @OA\Property(property="capacity_kg", type="integer", example=500, nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Vehículo creado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículo creado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicle = Vehicle::create([
            'store_id' => $storeId,
            'name' => $request->name,
            'plate' => $request->plate,
            'type' => $request->type,
            'capacity_kg' => $request->capacity_kg,
            'is_active' => true,
        ]);

        $vehicle->load('store');

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo creado exitosamente.',
            'data' => VehicleResource::make($vehicle),
            'errors' => null,
        ], 201);
    }

    /**
     * Display the specified vehicle.
     *
     * @OA\Get(
     *   path="/store/vehicles/{id}",
     *   summary="Ver un vehículo específico",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Vehículo obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículo obtenido exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Vehículo no encontrado")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicle = Vehicle::forStore($storeId)->find($id);

        if (! $vehicle) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehículo no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El vehículo no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $vehicle->load('store');

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo obtenido exitosamente.',
            'data' => VehicleResource::make($vehicle),
            'errors' => null,
        ]);
    }

    /**
     * Update the specified vehicle.
     *
     * @OA\Put(
     *   path="/store/vehicles/{id}",
     *   summary="Actualizar un vehículo",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="name", type="string", example="Furgoneta Blanca", nullable=true),
     *       @OA\Property(property="plate", type="string", example="ABC-1234", nullable=true),
     *       @OA\Property(property="type", type="string", enum={"auto", "moto", "bicicleta", "camioneta", "camion"}, nullable=true),
     *       @OA\Property(property="capacity_kg", type="integer", example=500, nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Vehículo actualizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículo actualizado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Vehículo no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function update(UpdateVehicleRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicle = Vehicle::forStore($storeId)->find($id);

        if (! $vehicle) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehículo no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El vehículo no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $vehicle->update($request->validated());

        $vehicle->load('store');

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo actualizado exitosamente.',
            'data' => VehicleResource::make($vehicle),
            'errors' => null,
        ]);
    }

    /**
     * Remove the specified vehicle.
     *
     * @OA\Delete(
     *   path="/store/vehicles/{id}",
     *   summary="Eliminar un vehículo",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Vehículo eliminado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículo eliminado exitosamente."),
     *       @OA\Property(property="data", type="null", example=null),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Vehículo no encontrado")
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicle = Vehicle::forStore($storeId)->find($id);

        if (! $vehicle) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehículo no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El vehículo no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $vehicle->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo eliminado exitosamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Toggle the active state of a vehicle.
     *
     * @OA\Patch(
     *   path="/store/vehicles/{id}/toggle-active",
     *   summary="Activar o desactivar un vehículo",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"is_active"},
     *
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *       @OA\Property(property="inactivation_reason", type="string", enum={"maintenance", "repair", "accident", "unavailable", "other"}, nullable=true, description="Requerido si is_active es false"),
     *       @OA\Property(property="inactivation_notes", type="string", nullable=true, example="En el taller por cambio de filtros")
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Estado del vehículo actualizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Vehículo activado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleResource"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=404, description="Vehículo no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function toggleActive(ToggleVehicleActiveRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $vehicle = Vehicle::forStore($storeId)->find($id);

        if (! $vehicle) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehículo no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El vehículo no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        if ($request->boolean('is_active')) {
            $vehicle->update([
                'is_active' => true,
                'inactivation_reason' => null,
                'inactivation_notes' => null,
            ]);
        } else {
            $vehicle->update([
                'is_active' => false,
                'inactivation_reason' => $request->inactivation_reason,
                'inactivation_notes' => $request->inactivation_notes,
            ]);
        }

        $vehicle->load('store');

        return response()->json([
            'status' => 'success',
            'message' => $vehicle->is_active
                ? 'Vehículo activado exitosamente.'
                : 'Vehículo desactivado exitosamente.',
            'data' => VehicleResource::make($vehicle),
            'errors' => null,
        ]);
    }
}
