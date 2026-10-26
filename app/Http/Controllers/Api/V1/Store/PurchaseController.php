<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StorePurchaseRequest;
use App\Http\Requests\Api\V1\Store\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseListResource;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\Store;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchaseService, private readonly PurchaseOrderPdfService $purchaseOrderPdfService) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:draft,ordered,partially_received,received,closed,cancelled'], 'purchase_type' => ['nullable', 'in:order,direct'], 'supplier_id' => ['nullable', 'uuid'], 'documents' => ['nullable', 'in:with_documents,without_documents'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $purchases = Purchase::forStore($request->user()->store_id)
            ->withCount('documents')
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('purchase_number', 'like', "%{$search}%")->orWhere('supplier_business_name', 'like', "%{$search}%")))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['purchase_type'] ?? null, fn ($query, $type) => $query->where('purchase_type', $type))
            ->when($data['supplier_id'] ?? null, fn ($query, $supplier) => $query->where('supplier_id', $supplier))
            ->when(($data['documents'] ?? null) === 'with_documents', fn ($query) => $query->has('documents'))
            ->when(($data['documents'] ?? null) === 'without_documents', fn ($query) => $query->where('status', '!=', 'draft')->doesntHave('documents'))
            ->latest()->paginate($request->integer('per_page', 15));

        return response()->json(['status' => 'success', 'message' => 'Compras obtenidas exitosamente.', 'data' => ['items' => PurchaseListResource::collection($purchases->items()), 'total' => $purchases->total(), 'per_page' => $purchases->perPage(), 'current_page' => $purchases->currentPage(), 'last_page' => $purchases->lastPage()], 'errors' => null]);
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchaseService->create($request->validated(), $request->user()->store_id, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Compra creada exitosamente.', 'data' => new PurchaseResource($purchase), 'errors' => null], 201);
    }

    public function show(Request $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->with(['items', 'receipts.items', 'receipts.createdBy', 'documents.createdBy'])->find($purchase);
        if (! $model) {
            abort(404);
        }

        return response()->json(['status' => 'success', 'message' => 'Compra obtenida exitosamente.', 'data' => new PurchaseResource($model), 'errors' => null]);
    }

    public function pdf(Request $request, string $purchase): Response
    {
        $model = Purchase::forStore($request->user()->store_id)->with('items')->find($purchase);
        if (! $model) {
            abort(404);
        }

        return $this->purchaseOrderPdfService->download($model, Store::findOrFail($request->user()->store_id));
    }

    public function update(UpdatePurchaseRequest $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->find($purchase);
        if (! $model) {
            abort(404);
        }
        $model = $this->purchaseService->update($model, $request->validated(), $request->user()->store_id, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Compra actualizada exitosamente.', 'data' => new PurchaseResource($model), 'errors' => null]);
    }

    public function order(Request $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->find($purchase);
        if (! $model) {
            abort(404);
        }
        $model = $this->purchaseService->order($model, $request->user()->store_id, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Orden de compra emitida exitosamente.', 'data' => new PurchaseResource($model), 'errors' => null]);
    }

    public function cancel(Request $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->find($purchase);
        if (! $model) {
            abort(404);
        }
        $model = $this->purchaseService->cancel($model, $request->user()->store_id, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Compra cancelada exitosamente.', 'data' => new PurchaseResource($model), 'errors' => null]);
    }

    public function close(Request $request, string $purchase): JsonResponse
    {
        $model = Purchase::forStore($request->user()->store_id)->find($purchase);
        if (! $model) {
            abort(404);
        }
        $model = $this->purchaseService->close($model, $request->user()->store_id, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Compra cerrada exitosamente.', 'data' => new PurchaseResource($model), 'errors' => null]);
    }
}
