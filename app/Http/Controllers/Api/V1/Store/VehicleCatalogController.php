<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VehicleCatalogController extends Controller
{
    /**
     * Return the list of valid vehicle types from config.
     *
     * @OA\Get(
     *   path="/store/vehicles/catalogs/types",
     *   summary="Listar tipos de vehículo",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Tipos de vehículo obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Tipos de vehículo obtenidos exitosamente."),
     *       @OA\Property(property="data", type="array", @OA\Items(type="string"), example={"auto", "moto", "bicicleta", "camioneta", "camion"}),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   )
     * )
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Tipos de vehículo obtenidos exitosamente.',
            'data' => config('vehicle_catalogs.types'),
            'errors' => null,
        ]);
    }

    /**
     * Return the list of valid inactivation reasons from config.
     *
     * @OA\Get(
     *   path="/store/vehicles/catalogs/reasons",
     *   summary="Listar motivos de inactivación",
     *   tags={"Store - Vehículos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Motivos de inactivación obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Motivos de inactivación obtenidos exitosamente."),
     *       @OA\Property(property="data", type="array", @OA\Items(type="string"), example={"maintenance", "repair", "accident", "unavailable", "other"}),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   )
     * )
     */
    public function reasons(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Motivos de inactivación obtenidos exitosamente.',
            'data' => config('vehicle_catalogs.inactivation_reasons'),
            'errors' => null,
        ]);
    }
}
