<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\ProductSearchRequest;
use App\Http\Resources\ProductSearchResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductSearchController extends Controller
{
    /**
     * Buscar productos por nombre o SKU para autocompletado.
     *
     * @OA\Get(
     *   path="/store/products/search",
     *   summary="Buscar productos por nombre o SKU",
     *   description="Endpoint ligero para alimentar componentes de autocompletado en el frontend. Retorna máximo 10 productos activos.",
     *   tags={"Store - Productos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string", minLength=2, maxLength=255), description="Término de búsqueda (mínimo 2 caracteres)"),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Resultados de búsqueda",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Productos encontrados."),
     *       @OA\Property(property="data", type="array",
     *
     *         @OA\Items(
     *
     *           @OA\Property(property="id", type="string", format="uuid"),
     *           @OA\Property(property="name", type="string"),
     *           @OA\Property(property="sku", type="string")
     *         )
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
    public function __invoke(ProductSearchRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $q = $request->validated('q');

        $products = Product::forStore($storeId)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', '%'.$q.'%')
                    ->orWhere('sku', 'like', '%'.$q.'%');
            })
            ->limit(10)
            ->get(['id', 'name', 'sku']);

        return response()->json([
            'status' => 'success',
            'message' => 'Productos encontrados.',
            'data' => ProductSearchResource::collection($products),
            'errors' => null,
        ]);
    }
}
