<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\CommercialProductCatalogRequest;
use App\Http\Resources\CommercialProductCatalogResource;
use App\Services\CommercialProductCatalogService;
use Illuminate\Http\JsonResponse;

class CommercialProductCatalogController extends Controller
{
    /**
     * @OA\Get(
     *   path="/store/operations/products/search",
     *   summary="Buscar productos vendibles para operaciones comerciales",
     *   description="Catálogo comercial para POS y pedidos. Retorna hasta 10 productos activos de la tienda actual por nombre, SKU o código de barras.",
     *   tags={"Store - Operaciones Comerciales"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="q", in="query", required=false, @OA\Schema(type="string", minLength=2, maxLength=255), description="Búsqueda parcial por nombre o SKU. No combinar con barcode."),
     *   @OA\Parameter(name="barcode", in="query", required=false, @OA\Schema(type="string", minLength=1, maxLength=100), description="Código de barras exacto. No combinar con q."),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Productos vendibles obtenidos exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Productos vendibles obtenidos exitosamente."),
     *       @OA\Property(
     *         property="data",
     *         type="array",
     *
     *         @OA\Items(
     *
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="name", type="string", example="Cinta Métrica 5m"),
     *           @OA\Property(property="sku", type="string", nullable=true, example="CINTA-5M"),
     *           @OA\Property(property="barcode", type="string", nullable=true, example="7791234567890")
     *         )
     *       ),
     *       @OA\Property(property="errors", type="object", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="Feature POS no disponible"),
     *   @OA\Response(response=422, description="Debe informar q o barcode")
     * )
     */
    public function __invoke(
        CommercialProductCatalogRequest $request,
        CommercialProductCatalogService $catalog
    ): JsonResponse {
        $products = $catalog->search(
            $request->user()->store_id,
            $request->validated('q'),
            $request->validated('barcode')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Productos vendibles obtenidos exitosamente.',
            'data' => CommercialProductCatalogResource::collection($products),
            'errors' => null,
        ]);
    }
}
