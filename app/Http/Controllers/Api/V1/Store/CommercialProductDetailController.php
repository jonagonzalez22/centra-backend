<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommercialProductDetailResource;
use App\Services\CommercialProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialProductDetailController extends Controller
{
    /**
     * @OA\Get(
     *   path="/store/operations/products/{id}",
     *   summary="Ver detalle comercial de un producto vendible",
     *   description="Retorna el precio de venta actual y stock disponible de un producto activo de la tienda actual para utilizarlo en una operación comercial.",
     *   tags={"Store - Operaciones Comerciales"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid"), description="ID del producto"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Detalle comercial obtenido exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Detalle comercial obtenido exitosamente."),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         @OA\Property(property="id", type="string", format="uuid"),
     *         @OA\Property(property="name", type="string", example="Cinta Métrica 5m"),
     *         @OA\Property(property="sku", type="string", nullable=true, example="CINTA-5M"),
     *         @OA\Property(property="barcode", type="string", nullable=true, example="7791234567890"),
     *         @OA\Property(property="price", type="number", format="float", example=7500),
     *         @OA\Property(property="available_stock", type="integer", example=10)
     *       ),
     *       @OA\Property(property="errors", type="object", nullable=true)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="Feature POS no disponible"),
     *   @OA\Response(response=404, description="Producto vendible no encontrado")
     * )
     */
    public function __invoke(
        Request $request,
        string $id,
        CommercialProductCatalogService $catalog
    ): JsonResponse {
        $product = $catalog->findActiveForStore($request->user()->store_id, $id);

        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Producto vendible no encontrado.',
                'data' => null,
                'errors' => ['id' => ['El producto no existe, no está activo o no pertenece a tu tienda.']],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Detalle comercial obtenido exitosamente.',
            'data' => CommercialProductDetailResource::make($product),
            'errors' => null,
        ]);
    }
}
