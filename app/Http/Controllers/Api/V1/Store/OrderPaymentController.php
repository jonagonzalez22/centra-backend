<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreOrderPaymentRequest;
use App\Http\Resources\CommercialOperationResource;
use App\Models\CommercialOperation;
use App\Services\OrderHistoryBuilder;
use App\Services\StoreOperationPaymentService;
use Illuminate\Http\JsonResponse;

class OrderPaymentController extends Controller
{
    /**
     * Register a payment against an existing order using the authenticated user's open cash session.
     *
     * @OA\Post(
     *   path="/store/orders/{order}/payments",
     *   summary="Registrar un pago posterior de un pedido",
     *   tags={"Store - Pedidos"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="order", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"store_payment_method_id", "amount"},
     *
     *     @OA\Property(property="store_payment_method_id", type="string", format="uuid"),
     *     @OA\Property(property="amount", type="number", format="float", example=10000),
     *     @OA\Property(property="reference", type="string", nullable=true)
     *   )),
     *
     *   @OA\Response(response=201, description="Pago registrado", @OA\JsonContent(ref="#/components/schemas/CommercialOperationResource")),
     *   @OA\Response(response=403, description="Sin permiso orders.collect"),
     *   @OA\Response(response=404, description="Pedido no encontrado"),
     *   @OA\Response(response=422, description="Saldo, caja o medio de pago inválido")
     * )
     */
    public function store(
        StoreOrderPaymentRequest $request,
        string $orderId,
        StoreOperationPaymentService $service,
        OrderHistoryBuilder $historyBuilder
    ): JsonResponse {
        $operation = CommercialOperation::forStore($request->user()->store_id)->find($orderId);
        if (! $operation || $operation->type !== 'order') {
            return response()->json([
                'status' => 'error',
                'message' => 'Pedido no encontrado.',
                'data' => null,
                'errors' => ['order' => ['El pedido no existe o no pertenece a tu tienda.']],
            ], 404);
        }

        $operation = $service->registerOrderPayment($operation, $request->validated(), $request->user());
        $operation->load([
            'customer.addresses.locality', 'items.product',
            'payments.storePaymentMethod.paymentMethod', 'payments.cashSession', 'payments.registeredBy',
            'user', 'routeStops.items.product', 'routeStops.route',
        ])->loadSum('payments', 'amount');
        $historyBuilder->attach($operation, $operation->store_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Pago registrado exitosamente.',
            'data' => CommercialOperationResource::make($operation),
            'errors' => null,
        ], 201);
    }
}
