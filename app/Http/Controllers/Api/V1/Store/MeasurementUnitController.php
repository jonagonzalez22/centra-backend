<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeasurementUnitResource;
use App\Models\MeasurementUnit;
use Illuminate\Http\JsonResponse;

class MeasurementUnitController extends Controller
{
    /**
     * @OA\Get(
     *   path="/store/measurement-units",
     *   summary="Listar unidades de medida activas",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(response=200, description="Unidades de medida obtenidas exitosamente"),
     *   @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(): JsonResponse
    {
        $units = MeasurementUnit::query()
            ->active()
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Unidades de medida obtenidas exitosamente.',
            'data' => MeasurementUnitResource::collection($units),
            'errors' => null,
        ]);
    }
}
