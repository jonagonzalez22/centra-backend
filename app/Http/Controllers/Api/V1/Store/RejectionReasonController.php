<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryRejectionReasonResource;
use App\Models\DeliveryRejectionReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RejectionReasonController extends Controller
{
    /**
     * List active rejection reasons — global plus store-specific.
     *
     * @OA\Get(
     *   path="/store/logistics/rejection-reasons",
     *   summary="Listar motivos de rechazo",
     *   tags={"Store - Logística"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Response(
     *     response=200,
     *     description="Listado de motivos de rechazo",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="null", example=null),
     *       @OA\Property(
     *         property="data",
     *         type="array",
     *
     *         @OA\Items(ref="#/components/schemas/RejectionReason")
     *       ),
     *       @OA\Property(property="errors", type="null", example=null),
     *       @OA\Property(property="meta", type="null", example=null)
     *     )
     *   )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $reasons = DeliveryRejectionReason::forStore($storeId)
            ->where('is_active', true)
            ->orderByRaw('store_id IS NULL DESC')
            ->orderBy('label')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => null,
            'data' => DeliveryRejectionReasonResource::collection($reasons),
            'errors' => null,
            'meta' => null,
        ]);
    }
}
