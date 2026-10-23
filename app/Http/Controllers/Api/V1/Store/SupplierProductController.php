<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store\BulkStoreSupplierProductsRequest;
use App\Http\Requests\Api\V1\Store\StoreSupplierProductRequest;
use App\Http\Requests\Api\V1\Store\UpdateSupplierProductRequest;
use App\Http\Resources\SupplierProductCandidateResource;
use App\Http\Resources\SupplierProductResource;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $supplierProduct = DB::transaction(function () use ($request, $supplierModel, $product) {
            $this->lockProductAssociations($request->user()->store_id, $product->id);

            if ($request->boolean('is_preferred')) {
                $this->clearPreferredForProduct($request->user()->store_id, $product->id);
            }

            return SupplierProduct::create([
                'store_id' => $request->user()->store_id,
                'supplier_id' => $supplierModel->id,
                'product_id' => $product->id,
                'supplier_code' => $request->validated('supplier_code'),
                'supplier_product_name' => $request->validated('supplier_product_name'),
                'is_preferred' => $request->boolean('is_preferred'),
            ]);
        });
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

        $validated = $request->validated();
        DB::transaction(function () use ($request, $supplierProductModel, $validated) {
            if (array_key_exists('is_preferred', $validated)) {
                $this->lockProductAssociations($request->user()->store_id, $supplierProductModel->product_id);

                if ($validated['is_preferred']) {
                    $this->clearPreferredForProduct($request->user()->store_id, $supplierProductModel->product_id);
                }
            }

            $supplierProductModel->update($validated);
        });
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
        $result = DB::transaction(function () use ($request, $supplierProductModel) {
            $storeId = $request->user()->store_id;
            $this->lockProductAssociations($storeId, $supplierProductModel->product_id);
            if ($this->hasDraftPurchaseItem($storeId, $supplierProductModel->supplier_id, $supplierProductModel->product_id)) {
                return $this->validationResponse('supplier_product', 'No se puede desasociar este producto porque está incluido en una compra en borrador de este proveedor. Quitalo de la compra o cancelá el borrador antes de desasociarlo.');
            }
            $supplierProductModel->delete();
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Producto desvinculado del proveedor exitosamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    public function bulk(BulkStoreSupplierProductsRequest $request, string $supplier): JsonResponse
    {
        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $result = DB::transaction(function () use ($request, $supplierModel) {
            $storeId = $request->user()->store_id;
            $productIds = $request->validated('product_ids');
            sort($productIds, SORT_STRING);
            $markAsPreferred = $request->boolean('mark_as_preferred');
            $products = Product::forStore($storeId)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                return $this->validationResponse('product_ids', 'Uno o más productos no existen o no pertenecen a tu tienda.');
            }

            foreach ($products as $product) {
                if (! $product->is_active) {
                    return $this->validationResponse('product_ids', 'Solo podés asociar productos activos.');
                }
                if ($product->stock_source_product_id !== null) {
                    return $this->validationResponse('product_ids', 'Los productos con stock compartido no pueden asociarse como productos de compra. Seleccioná el producto que administra el stock físico.');
                }
            }

            $targetAssociations = SupplierProduct::forStore($storeId)
                ->where('supplier_id', $supplierModel->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            if ($markAsPreferred) {
                SupplierProduct::forStore($storeId)
                    ->whereIn('product_id', $productIds)
                    ->orderBy('product_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $existingProductIds = $targetAssociations->keys()->all();
            $newProductIds = array_values(array_diff($productIds, $existingProductIds));
            foreach ($newProductIds as $productId) {
                SupplierProduct::create([
                    'store_id' => $storeId,
                    'supplier_id' => $supplierModel->id,
                    'product_id' => $productId,
                    'is_preferred' => $markAsPreferred,
                ]);
            }

            $preferredUpdatedCount = 0;
            if ($markAsPreferred) {
                $preferredUpdatedCount = $targetAssociations
                    ->filter(fn (SupplierProduct $association) => ! $association->is_preferred)
                    ->count();

                if ($preferredUpdatedCount > 0) {
                    SupplierProduct::forStore($storeId)
                        ->where('supplier_id', $supplierModel->id)
                        ->whereIn('product_id', $targetAssociations->keys())
                        ->where('is_preferred', false)
                        ->update(['is_preferred' => true]);
                }

                SupplierProduct::forStore($storeId)
                    ->whereIn('product_id', $productIds)
                    ->where('supplier_id', '!=', $supplierModel->id)
                    ->where('is_preferred', true)
                    ->update(['is_preferred' => false]);
            }

            return [
                'created_count' => count($newProductIds),
                'preferred_updated_count' => $preferredUpdatedCount,
                'skipped_existing_count' => count($existingProductIds) - $preferredUpdatedCount,
            ];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Productos asociados al proveedor exitosamente.',
            'data' => $result,
            'errors' => null,
        ], 201);
    }

    public function candidates(Request $request, string $supplier): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'source_supplier_id' => ['nullable', 'uuid'],
            'include_associated' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $supplierModel = $this->supplierForStore($request, $supplier);
        if (! $supplierModel) {
            return $this->supplierNotFound();
        }

        $storeId = $request->user()->store_id;
        $sourceSupplier = null;
        if ($request->filled('source_supplier_id')) {
            $sourceSupplier = Supplier::forStore($storeId)->find($request->input('source_supplier_id'));
            if (! $sourceSupplier) {
                return $this->validationResponse('source_supplier_id', 'El proveedor de origen no existe o no pertenece a tu tienda.');
            }
            if ($sourceSupplier->id === $supplierModel->id) {
                return $this->validationResponse('source_supplier_id', 'El proveedor de origen debe ser distinto al proveedor destino.');
            }
        }
        $searchTerm = $request->input('search', $request->input('q'));
        $includeAssociated = $request->boolean('include_associated');
        $candidates = Product::forStore($storeId)
            ->select('products.*')
            ->selectRaw(
                'exists (select 1 from supplier_products where supplier_products.product_id = products.id and supplier_products.store_id = ? and supplier_products.supplier_id = ?) as is_associated',
                [$storeId, $supplierModel->id]
            )
            ->selectRaw(
                '(select id from supplier_products where supplier_products.product_id = products.id and supplier_products.store_id = ? and supplier_products.supplier_id = ? limit 1) as candidate_supplier_product_id',
                [$storeId, $supplierModel->id]
            )
            ->selectRaw(
                '(select supplier_code from supplier_products where supplier_products.product_id = products.id and supplier_products.store_id = ? and supplier_products.supplier_id = ? limit 1) as candidate_supplier_code',
                [$storeId, $supplierModel->id]
            )
            ->selectRaw(
                '(select supplier_product_name from supplier_products where supplier_products.product_id = products.id and supplier_products.store_id = ? and supplier_products.supplier_id = ? limit 1) as candidate_supplier_product_name',
                [$storeId, $supplierModel->id]
            )
            ->selectRaw(
                '(select is_preferred from supplier_products where supplier_products.product_id = products.id and supplier_products.store_id = ? and supplier_products.supplier_id = ? limit 1) as candidate_supplier_is_preferred',
                [$storeId, $supplierModel->id]
            )
            ->where('is_active', true)
            ->whereNull('stock_source_product_id')
            ->when(! $includeAssociated, function ($query) use ($supplierModel, $storeId) {
                $query->whereNotExists(function ($query) use ($supplierModel, $storeId) {
                    $query->selectRaw('1')
                        ->from('supplier_products')
                        ->whereColumn('supplier_products.product_id', 'products.id')
                        ->where('supplier_products.store_id', $storeId)
                        ->where('supplier_products.supplier_id', $supplierModel->id);
                });
            })
            ->when($sourceSupplier, function ($query) use ($sourceSupplier, $storeId) {
                $query->whereExists(function ($sourceAssociations) use ($sourceSupplier, $storeId) {
                    $sourceAssociations->selectRaw('1')
                        ->from('supplier_products as source_supplier_products')
                        ->whereColumn('source_supplier_products.product_id', 'products.id')
                        ->where('source_supplier_products.store_id', $storeId)
                        ->where('source_supplier_products.supplier_id', $sourceSupplier->id);
                });
            })
            ->with(['stockMeasurementUnit', 'brand'])
            ->when(filled($searchTerm), function ($query) use ($searchTerm) {
                $query->where(function ($search) use ($searchTerm) {
                    $search->where('name', 'like', '%'.$searchTerm.'%')
                        ->orWhere('sku', 'like', '%'.$searchTerm.'%');
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'message' => 'Productos candidatos obtenidos exitosamente.',
            'data' => [
                'items' => SupplierProductCandidateResource::collection($candidates->items()),
                'total' => $candidates->total(),
                'per_page' => $candidates->perPage(),
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
            ],
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

    private function lockProductAssociations(string $storeId, string $productId): void
    {
        Product::forStore($storeId)->whereKey($productId)->lockForUpdate()->firstOrFail();

        SupplierProduct::forStore($storeId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->get();
    }

    private function clearPreferredForProduct(string $storeId, string $productId): void
    {
        SupplierProduct::forStore($storeId)
            ->where('product_id', $productId)
            ->where('is_preferred', true)
            ->update(['is_preferred' => false]);
    }

    private function hasDraftPurchaseItem(string $storeId, string $supplierId, string $productId): bool
    {
        return Purchase::forStore($storeId)
            ->where('supplier_id', $supplierId)
            ->where('status', 'draft')
            ->whereHas('items', fn ($items) => $items->where('product_id', $productId))
            ->exists();
    }
}
