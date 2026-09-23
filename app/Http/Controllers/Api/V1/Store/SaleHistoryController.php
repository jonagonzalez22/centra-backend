<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\ListCommercialOperationsRequest;
use App\Http\Resources\CommercialOperationReceiptResource;
use App\Http\Resources\CommercialOperationResource;
use App\Models\CommercialOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleHistoryController extends Controller
{
    public function index(ListCommercialOperationsRequest $request): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc'));
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'desc';

        $sales = CommercialOperation::forStore($storeId)
            ->byType('sale')
            ->with(['customer', 'user', 'items', 'payments.storePaymentMethod'])
            ->withSum('payments', 'amount')
            ->when($request->filled('status'), fn ($query) => $query->byStatus($request->status))
            ->when($request->filled('operation_number'), fn ($query) => $query->where('operation_number', 'like', trim($request->operation_number).'%'))
            ->when($request->filled('date_from') || $request->filled('date_to'), fn ($query) => $query->betweenDates($request->date_from, $request->date_to))
            ->orderBy('created_at', $sortDirection)
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Ventas obtenidas exitosamente.',
            'data' => [
                'items' => CommercialOperationResource::collection($sales->items()),
                'total' => $sales->total(),
                'per_page' => $sales->perPage(),
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $sale = $this->saleForStore($request, $id, [
            'customer', 'user', 'items.product', 'payments.storePaymentMethod.paymentMethod',
        ]);

        if (! $sale) {
            return $this->notFound();
        }

        $sale->loadSum('payments', 'amount');

        return response()->json([
            'status' => 'success',
            'message' => 'Venta obtenida exitosamente.',
            'data' => CommercialOperationResource::make($sale),
            'errors' => null,
        ]);
    }

    public function receipt(Request $request, string $id): JsonResponse
    {
        $sale = $this->saleForStore($request, $id, [
            'store', 'customer', 'user',
            'items' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'payments' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'payments.storePaymentMethod.paymentMethod',
        ]);

        if (! $sale) {
            return $this->notFound();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Comprobante de venta obtenido exitosamente.',
            'data' => CommercialOperationReceiptResource::make($sale),
            'errors' => null,
        ]);
    }

    private function saleForStore(Request $request, string $id, array $with): ?CommercialOperation
    {
        return CommercialOperation::forStore($request->user()->store_id)
            ->byType('sale')
            ->with($with)
            ->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Venta no encontrada.',
            'data' => null,
            'errors' => ['id' => ['La venta no existe o no pertenece a tu tienda.']],
        ], 404);
    }
}
