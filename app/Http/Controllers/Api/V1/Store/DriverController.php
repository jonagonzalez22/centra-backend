<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Display a listing of drivers (users with STORE_DRIVER role) in the store.
     *
     * @OA\Get(
     *   path="/store/drivers",
     *   summary="Listar conductores de la tienda",
     *   tags={"Store - Conductores"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15), description="Items por página"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Conductores obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Conductores obtenidos exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/DriverResource")),
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

        $drivers = User::role('STORE_DRIVER')
            ->where('store_id', $storeId)
            ->with('roles')
            ->orderBy('name')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Conductores obtenidos exitosamente.',
            'data' => [
                'items' => DriverResource::collection($drivers->items()),
                'total' => $drivers->total(),
                'per_page' => $drivers->perPage(),
                'current_page' => $drivers->currentPage(),
                'last_page' => $drivers->lastPage(),
            ],
            'errors' => null,
        ]);
    }
}
