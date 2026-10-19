<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreBrandRequest;
use App\Http\Requests\Api\V1\Store\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['name' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean']]);

        $p = Brand::forStore($request->user()->store_id)->when($request->filled('name'), fn ($q) => $q->where('name', 'like', '%'.$request->name.'%'))->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))->orderBy('name')->paginate($request->integer('per_page', 15));

        return response()->json(['status' => 'success', 'message' => 'Marcas obtenidas exitosamente.', 'data' => ['items' => BrandResource::collection($p->items()), 'total' => $p->total(), 'per_page' => $p->perPage(), 'current_page' => $p->currentPage(), 'last_page' => $p->lastPage()], 'errors' => null]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $b = Brand::create(['store_id' => $request->user()->store_id, ...$request->validated(), 'is_active' => $request->boolean('is_active', true)]);

        return response()->json(['status' => 'success', 'message' => 'Marca creada exitosamente.', 'data' => BrandResource::make($b), 'errors' => null], 201);
    }

    public function show(Request $request, string $brand): JsonResponse
    {
        return $this->one($request, $brand);
    }

    public function update(UpdateBrandRequest $request, string $brand): JsonResponse
    {
        $b = Brand::forStore($request->user()->store_id)->find($brand);
        if (! $b) {
            return $this->notFound();
        }
        $b->update($request->validated());

        return response()->json(['status' => 'success', 'message' => 'Marca actualizada exitosamente.', 'data' => BrandResource::make($b), 'errors' => null]);
    }

    public function destroy(Request $request, string $brand): JsonResponse
    {
        $b = Brand::forStore($request->user()->store_id)->find($brand);
        if (! $b) {
            return $this->notFound();
        }
        if ($b->products()->exists()) {
            return response()->json(['status' => 'error', 'message' => 'No se puede eliminar la marca porque tiene productos asociados.', 'data' => null, 'errors' => ['brand' => ['La marca tiene productos asociados.']]], 409);
        }
        $b->delete();

        return response()->json(['status' => 'success', 'message' => 'Marca eliminada exitosamente.', 'data' => null, 'errors' => null]);
    }

    private function one(Request $request, string $id): JsonResponse
    {
        $b = Brand::forStore($request->user()->store_id)->find($id);
        if (! $b) {
            return $this->notFound();
        }

        return response()->json(['status' => 'success', 'message' => 'Marca obtenida exitosamente.', 'data' => BrandResource::make($b), 'errors' => null]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Marca no encontrada.', 'data' => null, 'errors' => ['id' => ['La marca no existe o no pertenece a tu tienda.']]], 404);
    }
}
