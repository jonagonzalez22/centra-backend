<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\GenerateSkuRequest;
use App\Http\Resources\SkuResource;
use App\Services\SkuGeneratorService;
use Illuminate\Http\JsonResponse;

class GenerateSkuController extends Controller
{
    /**
     * Generate a new SKU for products.
     *
     * @OA\Get(
     *   path="/store/products/generate-sku",
     *   summary="Generar un SKU único para productos",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="category_id", in="query", @OA\Schema(type="string", format="uuid"), description="ID de categoría para determinar el prefijo del SKU"),
     *   @OA\Parameter(name="name", in="query", @OA\Schema(type="string", maxLength=255), description="Nombre del producto para determinar el prefijo del SKU"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="SKU generado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="SKU generado exitosamente."),
     *       @OA\Property(property="data", type="object",
     *         @OA\Property(property="sku", type="string", example="FERR-000015")
     *       ),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=401, description="No autenticado"),
     *   @OA\Response(response=403, description="Feature no disponible en el plan"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function __invoke(
        GenerateSkuRequest $request,
        SkuGeneratorService $skuGenerator
    ): JsonResponse {
        $storeId = $request->user()->store_id;

        $sku = $skuGenerator->generate(
            $storeId,
            $request->input('category_id'),
            $request->input('name')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'SKU generado exitosamente.',
            'data' => SkuResource::make(['sku' => $sku]),
            'errors' => null,
        ]);
    }
}
