<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\StoreSupplierProductRequest;
use App\Http\Requests\Api\V1\Store\UpdateSupplierProductRequest;
use App\Http\Resources\SupplierProductResource;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierProductController extends Controller
{
    public function index(Request $request, string $supplier): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $supplierProducts = SupplierProduct::forStore($request->user()->store_id)
            ->where('supplier_id', $supplierModel->id)
            ->with('product.stockMeasurementUnit')
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($search) use ($request) {
                    $search->where('supplier_code', 'like', '%'.$request->search.'%')
                        ->orWhere('supplier_product_name', 'like', '%'.$request->search.'%')
                        ->orWhereHas('product', function ($products) use ($request) {
                            $products->where('name', 'like', '%'.$request->search.'%')
                                ->orWhere('sku', 'like', '%'.$request->search.'%');
                        });
                });
            })
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Productos del proveedor obtenidos exitosamente.',
            'data' => [
                'items' => SupplierProductResource::collection($supplierProducts->items()),
                'total' => $supplierProducts->total(),
                'per_page' => $supplierProducts->perPage(),
                'current_page' => $supplierProducts->currentPage(),
                'last_page' => $supplierProducts->lastPage(),
            ],
            'errors' => null,
        ]);
    }

    public function store(StoreSupplierProductRequest $request, string $supplier): JsonResponse
    {
        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $product = Product::forStore($request->user()->store_id)->find($request->validated('product_id'));
        if (! $product) {
            return $this->validationResponse('product_id', 'El producto no existe o no pertenece a tu tienda.');
        }
        if ($product->stock_source_product_id !== null) {
            return $this->validationResponse('product_id', 'Los productos con stock compartido no pueden asociarse como productos de compra. Seleccioná el producto que administra el stock físico.');
        }

        $supplierProduct = SupplierProduct::create([
            'store_id' => $request->user()->store_id,
            'supplier_id' => $supplierModel->id,
            'product_id' => $product->id,
            'supplier_code' => $request->validated('supplier_code'),
            'supplier_product_name' => $request->validated('supplier_product_name'),
        ]);
        $supplierProduct->load('product.stockMeasurementUnit');

        return response()->json([
            'status' => 'success',
            'message' => 'Producto asociado al proveedor exitosamente.',
            'data' => new SupplierProductResource($supplierProduct),
            'errors' => null,
        ], 201);
    }

    public function update(UpdateSupplierProductRequest $request, string $supplier, string $supplierProduct): JsonResponse
    {
        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $supplierProductModel = $this->supplierProductForSupplier($request, $supplierModel, $supplierProduct);
        if (! $supplierProductModel) {
            return $this->supplierProductNotFound();
        }

        $supplierProductModel->update($request->validated());
        $supplierProductModel->load('product.stockMeasurementUnit');

        return response()->json([
            'status' => 'success',
            'message' => 'Producto del proveedor actualizado exitosamente.',
            'data' => new SupplierProductResource($supplierProductModel),
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, string $supplier, string $supplierProduct): JsonResponse
    {
        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $supplierProductModel = $this->supplierProductForSupplier($request, $supplierModel, $supplierProduct);
        if (! $supplierProductModel) {
            return $this->supplierProductNotFound();
        }
        $supplierProductModel->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Producto desvinculado del proveedor exitosamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    private function supplierForStore(Request $request, string $supplier): ?Supplier
    {
        return Supplier::forStore($request->user()->store_id)->find($supplier);
    }

    private function supplierProductForSupplier(Request $request, Supplier $supplier, string $supplierProduct): ?SupplierProduct
    {
        return SupplierProduct::forStore($request->user()->store_id)
            ->where('supplier_id', $supplier->id)
            ->find($supplierProduct);
    }

    private function supplierNotFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Proveedor no encontrado.',
            'data' => null,
            'errors' => null,
        ], 404);
    }

    private function supplierProductNotFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Producto del proveedor no encontrado.',
            'data' => null,
            'errors' => null,
        ], 404);
    }

    private function validationResponse(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
