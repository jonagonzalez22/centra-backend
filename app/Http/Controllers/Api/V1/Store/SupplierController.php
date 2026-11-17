<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Store\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Models\Purchase;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function operationalOptions(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255']]);

        $suppliers = Supplier::forStore($request->user()->store_id)
            ->where('is_active', true)
            ->when($request->filled('search'), fn ($query) => $query->where('business_name', 'like', '%'.$request->input('search').'%'))
            ->orderBy('business_name')
            ->get(['id', 'business_name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Opciones de proveedores obtenidas exitosamente.',
            'data' => ['items' => $suppliers],
            'errors' => null,
        ]);
    }

    public function index(Request $r): JsonResponse
    {
        $r->validate(['search' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean']]);
        $p = Supplier::forStore($r->user()->store_id)->when($r->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('business_name', 'like', '%'.$r->search.'%')->orWhere('tax_id', 'like', '%'.$r->search.'%')->orWhere('contact_name', 'like', '%'.$r->search.'%')))->when($r->has('is_active'), fn ($q) => $q->where('is_active', $r->boolean('is_active')))->orderBy('business_name')->paginate($r->integer('per_page', 15));

        return response()->json(['status' => 'success', 'message' => 'Proveedores obtenidos exitosamente.', 'data' => ['items' => SupplierResource::collection($p->items()), 'total' => $p->total(), 'per_page' => $p->perPage(), 'current_page' => $p->currentPage(), 'last_page' => $p->lastPage()], 'errors' => null]);
    }

    public function store(StoreSupplierRequest $r): JsonResponse
    {
        $s = Supplier::create(['store_id' => $r->user()->store_id, ...$r->validated(), 'is_active' => $r->boolean('is_active', true)]);

        return response()->json(['status' => 'success', 'message' => 'Proveedor creado exitosamente.', 'data' => new SupplierResource($s), 'errors' => null], 201);
    }

    public function show(Request $r, string $supplier): JsonResponse
    {
        return $this->respond($r, $supplier);
    }

    public function update(UpdateSupplierRequest $r, string $supplier): JsonResponse
    {
        $s = Supplier::forStore($r->user()->store_id)->find($supplier);
        if (! $s) {
            return $this->notFound();
        }
        $s->update($r->validated());

        return response()->json(['status' => 'success', 'message' => 'Proveedor actualizado exitosamente.', 'data' => new SupplierResource($s), 'errors' => null]);
    }

    public function destroy(Request $r, string $supplier): JsonResponse
    {
        $s = Supplier::forStore($r->user()->store_id)->find($supplier);
        if (! $s) {
            return $this->notFound();
        }
        if ($s->supplierProducts()->exists() || Purchase::forStore($r->user()->store_id)->where('supplier_id', $s->id)->exists()) {
            return $this->inUse();
        }

        try {
            $s->delete();
        } catch (QueryException) {
            // The explicit precondition provides a clear response in the usual
            // case; retain it for a concurrent reference created after it.
            return $this->inUse();
        }

        return response()->json(['status' => 'success', 'message' => 'Proveedor eliminado exitosamente.', 'data' => null, 'errors' => null]);
    }

    private function respond(Request $r, string $id): JsonResponse
    {
        $s = Supplier::forStore($r->user()->store_id)->find($id);

        return $s ? response()->json(['status' => 'success', 'message' => 'Proveedor obtenido exitosamente.', 'data' => new SupplierResource($s), 'errors' => null]) : $this->notFound();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Proveedor no encontrado.', 'data' => null, 'errors' => null], 404);
    }

    private function inUse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'No se puede eliminar el proveedor porque tiene asociaciones o compras relacionadas.',
            'data' => null,
            'errors' => ['supplier' => ['El proveedor está siendo utilizado y debe conservarse por motivos comerciales.']],
        ], 409);
    }
}
