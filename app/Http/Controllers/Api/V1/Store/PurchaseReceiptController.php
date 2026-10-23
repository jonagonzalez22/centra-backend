<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StorePurchaseReceiptRequest;
use App\Http\Resources\PurchaseReceiptResource;
use App\Models\Purchase;
use App\Services\PurchaseReceiptService;
use Illuminate\Http\JsonResponse;

class PurchaseReceiptController extends Controller
{
    public function __construct(private readonly PurchaseReceiptService $receiptService) {}

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
