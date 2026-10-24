<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StorePurchaseDocumentRequest;
use App\Http\Requests\Api\V1\Store\UpdatePurchaseDocumentRequest;
use App\Http\Resources\PurchaseDocumentResource;
use App\Models\Purchase;
use App\Models\PurchaseDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseDocumentController extends Controller
{
    public function index(Request $request, string $purchase): JsonResponse
    {
        $model = $this->purchaseForStore($request, $purchase);
        $documents = $model->documents()->with('createdBy')->orderByDesc('issued_at')->orderByDesc('created_at')->get();

        return response()->json(['status' => 'success', 'message' => 'Documentos obtenidos exitosamente.', 'data' => PurchaseDocumentResource::collection($documents), 'errors' => null]);
    }

    public function store(StorePurchaseDocumentRequest $request, string $purchase): JsonResponse
    {
        $document = DB::transaction(function () use ($request, $purchase) {
            $model = $this->purchaseForStore($request, $purchase, true);
            $this->ensureDocumentable($model);

            return $model->documents()->create([
                ...$request->validated(),
                'store_id' => $request->user()->store_id,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Documento registrado exitosamente.', 'data' => PurchaseDocumentResource::make($document->load('createdBy')), 'errors' => null], 201);
    }

    public function update(UpdatePurchaseDocumentRequest $request, string $purchase, string $document): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $purchase, $document) {
            $model = $this->purchaseForStore($request, $purchase, true);
            $this->ensureDocumentable($model);
            $record = $this->documentForPurchase($model, $request, $document, true);
            $record->update($request->validated());

            return $record->load('createdBy');
        });

        return response()->json(['status' => 'success', 'message' => 'Documento actualizado exitosamente.', 'data' => PurchaseDocumentResource::make($updated), 'errors' => null]);
    }

    public function destroy(Request $request, string $purchase, string $document): JsonResponse
    {
        DB::transaction(function () use ($request, $purchase, $document) {
            $model = $this->purchaseForStore($request, $purchase, true);
            $this->ensureDocumentable($model);
            $this->documentForPurchase($model, $request, $document, true)->delete();
        });

        return response()->json(['status' => 'success', 'message' => 'Documento eliminado exitosamente.', 'data' => null, 'errors' => null]);
    }

    private function purchaseForStore(Request $request, string $purchase, bool $lock = false): Purchase
    {
        $query = Purchase::forStore($request->user()->store_id)->whereKey($purchase);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function documentForPurchase(Purchase $purchase, Request $request, string $document, bool $lock = false): PurchaseDocument
    {
        $query = $purchase->documents()->where('store_id', $request->user()->store_id)->whereKey($document);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function ensureDocumentable(Purchase $purchase): void
    {
        if (! in_array($purchase->status, PurchaseDocument::PURCHASE_DOCUMENTABLE_STATUSES, true)) {
            abort(422, 'Sólo se pueden gestionar documentos de compras confirmadas y no canceladas.');
        }
    }
}
