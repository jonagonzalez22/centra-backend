<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StorePurchaseReceiptRequest;
use App\Http\Resources\PurchaseReceiptResource;
use App\Models\Purchase;
use App\Services\PurchaseReceiptService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PurchaseReceiptController extends Controller
{
    public function __construct(private readonly PurchaseReceiptService $receiptService) {}

    #[OA\Post(
        path: '/store/purchases/{purchase}/receipts',
        summary: 'Registrar una recepción de compra',
        tags: ['Store - Compras'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'purchase', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['items'], properties: [
            new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'update_reference_cost', type: 'boolean', default: false, description: 'Actualiza el costo de referencia de los productos efectivamente recibidos; no modifica la valuación promedio.'),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['purchase_item_id', 'quantity'], properties: [
                new OA\Property(property: 'purchase_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'string', example: '2.5000'),
            ])),
        ])),
        responses: [new OA\Response(response: 201, description: 'Recepción registrada exitosamente'), new OA\Response(response: 422, description: 'Error de validación')],
    )]
    public function store(StorePurchaseReceiptRequest $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->find($purchase);
        if (! $model) {
            abort(404);
        }

        $receipt = $this->receiptService->receive(
            $model,
            $request->validated(),
            $request->user()->store_id,
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Recepción registrada exitosamente.',
            'data' => new PurchaseReceiptResource($receipt),
            'errors' => null,
        ], 201);
    }
}
