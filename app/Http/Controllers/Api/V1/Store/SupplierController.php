<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Store\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
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
        $s->delete();

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
}
