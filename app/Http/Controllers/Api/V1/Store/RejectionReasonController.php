<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreRejectionReasonRequest;
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
     *
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
            'message' => 'Listado de motivos obtenidos exitosamente.',
            'data' => DeliveryRejectionReasonResource::collection($reasons),
            'errors' => null,
            'meta' => null,
        ]);
    }

    /**
     * Create a new rejection reason for the authenticated store.
     *
     * @OA\Post(
     *   path="/store/logistics/rejection-reasons",
     *   summary="Crear motivo de rechazo",
     *   tags={"Store - Logística"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *       required={"code", "label"},
     *
     *       @OA\Property(property="code", type="string", example="sin_efectivo"),
     *       @OA\Property(property="label", type="string", example="Sin efectivo para abonar"),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=201,
     *     description="Motivo creado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Motivo creado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/RejectionReason"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function store(StoreRejectionReasonRequest $request): JsonResponse
    {
        $reason = DeliveryRejectionReason::create([
            'store_id' => $request->user()->store_id,
            'code' => $request->code,
            'label' => $request->label,
            'is_active' => $request->boolean('is_active', true),
            'suggest_extra_sale' => $request->boolean('suggest_extra_sale', false),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Motivo creado exitosamente.',
            'data' => DeliveryRejectionReasonResource::make($reason),
            'errors' => null,
        ], 201);
    }

    /**
     * Update a rejection reason. Only for store-owned reasons, not global ones.
     *
     * @OA\Put(
     *   path="/store/logistics/rejection-reasons/{id}",
     *   summary="Editar motivo de rechazo",
     *   tags={"Store - Logística"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(
     *     required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="code", type="string", example="sin_efectivo"),
     *       @OA\Property(property="label", type="string", example="Sin efectivo para abonar"),
     *       @OA\Property(property="is_active", type="boolean", example=true)
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Motivo actualizado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Motivo actualizado exitosamente."),
     *       @OA\Property(property="data", ref="#/components/schemas/RejectionReason"),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No se pueden editar motivos globales"),
     *   @OA\Response(response=404, description="Motivo no encontrado"),
     *   @OA\Response(response=422, description="Error de validación")
     * )
     */
    public function update(StoreRejectionReasonRequest $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $reason = DeliveryRejectionReason::where('id', $id)
            ->where('store_id', $storeId)
            ->first();

        if (! $reason) {
            return response()->json([
                'status' => 'error',
                'message' => 'Motivo no encontrado o no pertenece a tu tienda.',
                'data' => null,
                'errors' => ['id' => ['El motivo no existe, es global o no pertenece a tu tienda.']],
            ], 404);
        }

        $reason->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Motivo actualizado exitosamente.',
            'data' => DeliveryRejectionReasonResource::make($reason),
            'errors' => null,
        ]);
    }

    /**
     * Soft-delete a rejection reason (mark as inactive). Only store-owned.
     *
     * @OA\Delete(
     *   path="/store/logistics/rejection-reasons/{id}",
     *   summary="Eliminar motivo de rechazo",
     *   tags={"Store - Logística"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\Response(
     *     response=200,
     *   description="Motivo eliminado exitosamente",
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="status", type="string", example="success"),
     *       @OA\Property(property="message", type="string", example="Motivo eliminado exitosamente."),
     *       @OA\Property(property="data", type="null", example=null),
     *       @OA\Property(property="errors", type="null", example=null)
     *     )
     *   ),
     *
     *   @OA\Response(response=403, description="No se pueden eliminar motivos globales"),
     *   @OA\Response(response=404, description="Motivo no encontrado")
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $storeId = $request->user()->store_id;

        $reason = DeliveryRejectionReason::where('id', $id)
            ->where('store_id', $storeId)
            ->first();

        if (! $reason) {
            return response()->json([
                'status' => 'error',
                'message' => 'Motivo no encontrado o no pertenece a tu tienda.',
                'data' => null,
                'errors' => ['id' => ['El motivo no existe, es global o no pertenece a tu tienda.']],
            ], 404);
        }

        $reason->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Motivo eliminado exitosamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
