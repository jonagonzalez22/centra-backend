<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['suppliers.view', 'suppliers.edit'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['suppliers.view', 'suppliers.edit']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Química Mendoza']);
});

function supplierProduct(string $supplier, string $product): string
{
    return "/api/v1/store/suppliers/{$supplier}/products/{$product}";
}

function productForSupplier(Store $store, Category $category, array $attributes = []): Product
{
    return Product::factory()->forStore($store)->create([
        'category_id' => $category->id,
        ...$attributes,
    ]);
}

function purchaseWithProduct(Store $store, Supplier $supplier, Product $product, User $user, string $type = 'order', string $status = 'draft'): Purchase
{
    $purchase = Purchase::create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'purchase_number' => 'OC-'.strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 6)),
        'purchase_type' => $type,
        'status' => $status,
        'supplier_business_name' => $supplier->business_name,
        'total' => '10.00',
        'created_by' => $user->id,
    ]);
    PurchaseItem::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_sku' => $product->sku,
        'measurement_unit_code' => 'unit',
        'measurement_unit_symbol' => 'u',
        'quantity_ordered' => '1.0000',
        'quantity_received' => '0.0000',
        'unit_price' => '10.00',
        'subtotal' => '10.00',
    ]);

    return $purchase;
}

test('associates a physical product and normalizes optional supplier metadata', function () {
    $product = productForSupplier($this->store, $this->category, [
        'name' => 'Cloro granel',
        'sku' => 'LQUI-000006',
        'is_sellable' => false,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", [
            'product_id' => $product->id,
            'supplier_code' => '',
            'supplier_product_name' => '  Hipoclorito jaula 500 litros  ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.supplier_code', null)
        ->assertJsonPath('data.supplier_product_name', 'Hipoclorito jaula 500 litros')
        ->assertJsonPath('data.product.id', $product->id)
        ->assertJsonPath('data.product.sku', 'LQUI-000006')
        ->assertJsonPath('data.product.is_sellable', false);

    $this->assertDatabaseHas('supplier_products', [
        'store_id' => $this->store->id,
        'supplier_id' => $this->supplier->id,
        'product_id' => $product->id,
        'supplier_code' => null,
    ]);
});

test('rejects duplicate supplier product associations but permits the same product for another supplier', function () {
    $product = productForSupplier($this->store, $this->category);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $product->id])->assertCreated();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $product->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$otherSupplier->id}/products", ['product_id' => $product->id])->assertCreated();
});

test('allows a supplier to provide multiple physical products and updates and deletes its metadata', function () {
    $first = productForSupplier($this->store, $this->category);
    $second = productForSupplier($this->store, $this->category);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $first->id])->assertCreated();
    $association = SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $first->id)->firstOrFail();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $second->id])->assertCreated();

    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), [
        'supplier_code' => '  QMI-CL500  ',
        'supplier_product_name' => '  Cloro proveedor  ',
    ])->assertOk()->assertJsonPath('data.supplier_code', 'QMI-CL500')->assertJsonPath('data.supplier_product_name', 'Cloro proveedor');

    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), [
        'supplier_code' => '',
        'supplier_product_name' => '',
    ])->assertOk()->assertJsonPath('data.supplier_code', null)->assertJsonPath('data.supplier_product_name', null);

    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertOk();
    $this->assertDatabaseMissing('supplier_products', ['id' => $association->id]);
    expect(SupplierProduct::query()->where('supplier_id', $this->supplier->id)->count())->toBe(1);
});

test('blocks supplier product removal when an order draft contains the product', function () {
    $product = productForSupplier($this->store, $this->category);
    $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    $purchase = purchaseWithProduct($this->store, $this->supplier, $product, $this->user, 'order');

    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.supplier_product.0', 'No se puede desasociar este producto porque está incluido en una compra en borrador de este proveedor. Quitalo de la compra o cancelá el borrador antes de desasociarlo.');

    $this->assertDatabaseHas('supplier_products', ['id' => $association->id]);
    $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'status' => 'draft']);
});

test('blocks supplier product removal when a direct draft contains the product', function () {
    $product = productForSupplier($this->store, $this->category);
    $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    purchaseWithProduct($this->store, $this->supplier, $product, $this->user, 'direct');

    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertUnprocessable();
    $this->assertDatabaseHas('supplier_products', ['id' => $association->id]);
});

test('does not block supplier product removal for another supplier draft or another product', function () {
    $product = productForSupplier($this->store, $this->category);
    $otherProduct = productForSupplier($this->store, $this->category);
    $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);
    purchaseWithProduct($this->store, $otherSupplier, $product, $this->user);
    purchaseWithProduct($this->store, $this->supplier, $otherProduct, $this->user);

    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertOk();
    $this->assertDatabaseMissing('supplier_products', ['id' => $association->id]);
});

test('does not block supplier product removal for ordered or cancelled purchases', function () {
    foreach (['ordered', 'cancelled'] as $status) {
        $product = productForSupplier($this->store, $this->category);
        $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
        purchaseWithProduct($this->store, $this->supplier, $product, $this->user, 'order', $status);

        $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertOk();
        $this->assertDatabaseMissing('supplier_products', ['id' => $association->id]);
    }
});

test('rejects shared-stock children while allowing a non-sellable physical source', function () {
    $source = productForSupplier($this->store, $this->category, ['is_sellable' => false]);
    $child = productForSupplier($this->store, $this->category, [
        'stock' => 0,
        'stock_min' => 0,
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '1.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $source->id])->assertCreated();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $child->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.product_id.0', 'Los productos con stock compartido no pueden asociarse como productos de compra. Seleccioná el producto que administra el stock físico.');
});

test('isolates associations across stores and rejects foreign supplier and product ids', function () {
    $purchases = Feature::query()->where('code', 'purchases')->firstOrFail();
    $otherPlan = Plan::factory()->create();
    $otherPlan->features()->attach($purchases->id);
    $otherStore = Store::factory()->create(['plan_id' => $otherPlan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $otherSupplier = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Proveedor ajeno']);
    $otherProduct = productForSupplier($otherStore, $otherCategory);
    $localProduct = productForSupplier($this->store, $this->category);
    $foreignAssociation = SupplierProduct::create(['store_id' => $otherStore->id, 'supplier_id' => $otherSupplier->id, 'product_id' => $otherProduct->id]);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $otherProduct->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$otherSupplier->id}/products", ['product_id' => $localProduct->id])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$otherSupplier->id}/products")->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $foreignAssociation->id), ['supplier_code' => 'NO'])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $foreignAssociation->id))->assertNotFound();
});

test('lists only associations for the requested supplier and store with search and pagination', function () {
    $product = productForSupplier($this->store, $this->category, ['name' => 'Cloro granel', 'sku' => 'CL-001']);
    $second = productForSupplier($this->store, $this->category, ['name' => 'Detergente', 'sku' => 'DET-001']);
    $third = productForSupplier($this->store, $this->category, ['name' => 'Jabón', 'sku' => 'JAB-001']);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id, 'supplier_code' => 'CL-500']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $second->id, 'supplier_product_name' => 'Detergente industrial']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $third->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $otherSupplier->id, 'product_id' => productForSupplier($this->store, $this->category)->id]);

    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products")
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=CL-001")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=CL-500")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=industrial")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $second->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?per_page=1&page=2")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.total', 3)->assertJsonPath('data.current_page', 2);
});

test('requires supplier permissions and the purchases feature', function () {
    $product = productForSupplier($this->store, $this->category);
    $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_ADMIN');
    $viewOnly->givePermissionTo('suppliers.view');
    $editOnly = User::factory()->create(['store_id' => $this->store->id]);
    $editOnly->assignRole('STORE_ADMIN');
    $editOnly->givePermissionTo('suppliers.edit');

    $this->actingAs($editOnly, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products")->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => productForSupplier($this->store, $this->category)->id])->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), ['supplier_code' => 'X'])->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertForbidden();

    $disabledStore = Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabledStore->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['suppliers.view', 'suppliers.edit']);
    $disabledSupplier = Supplier::create(['store_id' => $disabledStore->id, 'business_name' => 'Sin feature']);
    $this->actingAs($disabledUser, 'sanctum')->getJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products")->assertForbidden();
    $this->actingAs($disabledUser, 'sanctum')->postJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products")->assertForbidden();
    $this->actingAs($disabledUser, 'sanctum')->getJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products/candidates")->assertForbidden();
    $this->actingAs($disabledUser, 'sanctum')->postJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products/bulk", ['product_ids' => [productForSupplier($disabledStore, Category::factory()->belongsToStore($disabledStore)->create())->id]])->assertForbidden();
});

test('maintains at most one preferred supplier per product and allows clearing it', function () {
    $product = productForSupplier($this->store, $this->category);
    $otherProduct = productForSupplier($this->store, $this->category);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor alternativo']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $product->id, 'is_preferred' => true])
        ->assertCreated()
        ->assertJsonPath('data.is_preferred', true);
    $first = SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $product->id)->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$otherSupplier->id}/products", ['product_id' => $product->id, 'is_preferred' => true])
        ->assertCreated()
        ->assertJsonPath('data.is_preferred', true);

    $second = SupplierProduct::query()->where('supplier_id', $otherSupplier->id)->where('product_id', $product->id)->firstOrFail();
    expect($first->fresh()->is_preferred)->toBeFalse()
        ->and($second->fresh()->is_preferred)->toBeTrue();

    $this->actingAs($this->user, 'sanctum')
        ->putJson(supplierProduct($this->supplier->id, $first->id), ['is_preferred' => true])
        ->assertOk()
        ->assertJsonPath('data.is_preferred', true);
    expect($first->fresh()->is_preferred)->toBeTrue()
        ->and($second->fresh()->is_preferred)->toBeFalse();

    SupplierProduct::create([
        'store_id' => $this->store->id,
        'supplier_id' => $this->supplier->id,
        'product_id' => $otherProduct->id,
        'is_preferred' => true,
    ]);
    $this->actingAs($this->user, 'sanctum')
        ->putJson(supplierProduct($this->supplier->id, $first->id), ['is_preferred' => false])
        ->assertOk()
        ->assertJsonPath('data.is_preferred', false);

    expect(SupplierProduct::query()->where('product_id', $product->id)->where('is_preferred', true)->count())->toBe(0)
        ->and(SupplierProduct::query()->where('product_id', $otherProduct->id)->where('is_preferred', true)->count())->toBe(1);

    $this->actingAs($this->user, 'sanctum')
        ->putJson(supplierProduct($otherSupplier->id, $second->id), ['is_preferred' => true])
        ->assertOk();
    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($otherSupplier->id, $second->id))->assertOk();
    expect(SupplierProduct::query()->where('product_id', $product->id)->where('is_preferred', true)->count())->toBe(0);
});

test('bulk associates valid physical products, normalizes duplicate ids, and skips existing associations', function () {
    $existing = productForSupplier($this->store, $this->category, ['is_sellable' => false]);
    $first = productForSupplier($this->store, $this->category);
    $second = productForSupplier($this->store, $this->category);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $existing->id]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products/bulk", [
            'product_ids' => [$existing->id, $first->id, $first->id, $second->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.skipped_existing_count', 1);

    $newAssociation = SupplierProduct::query()
        ->where('supplier_id', $this->supplier->id)
        ->where('product_id', $first->id)
        ->firstOrFail();

    expect(SupplierProduct::query()->where('supplier_id', $this->supplier->id)->count())->toBe(3)
        ->and($newAssociation->supplier_code)->toBeNull()
        ->and($newAssociation->supplier_product_name)->toBeNull()
        ->and($newAssociation->is_preferred)->toBeFalse();
});

test('bulk supports one hundred products in a single operation', function () {
    $products = collect(range(1, 100))->map(fn () => productForSupplier($this->store, $this->category));

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products/bulk", [
            'product_ids' => $products->pluck('id')->all(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 100)
        ->assertJsonPath('data.skipped_existing_count', 0);

    expect(SupplierProduct::query()->where('supplier_id', $this->supplier->id)->count())->toBe(100);
});

test('bulk rejects inactive, shared-stock, and foreign products without creating partial associations', function () {
    $valid = productForSupplier($this->store, $this->category);
    $inactive = productForSupplier($this->store, $this->category, ['is_active' => false]);
    $child = productForSupplier($this->store, $this->category, ['stock_source_product_id' => $valid->id, 'stock_consumption_quantity' => '1.0000']);
    $purchases = Feature::query()->where('code', 'purchases')->firstOrFail();
    $foreignPlan = Plan::factory()->create();
    $foreignPlan->features()->attach($purchases->id);
    $foreignStore = Store::factory()->create(['plan_id' => $foreignPlan->id]);
    $foreignProduct = productForSupplier($foreignStore, Category::factory()->belongsToStore($foreignStore)->create());

    foreach ([$inactive->id, $child->id, $foreignProduct->id] as $invalidId) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products/bulk", ['product_ids' => [$valid->id, $invalidId]])
            ->assertUnprocessable();
        expect(SupplierProduct::query()->where('supplier_id', $this->supplier->id)->count())->toBe(0);
    }
});

test('returns paginated candidates only for products not associated to the requested supplier', function () {
    $associated = productForSupplier($this->store, $this->category, ['name' => 'Cloro asociado', 'sku' => 'ASO-001']);
    $candidate = productForSupplier($this->store, $this->category, ['name' => 'Detergente candidato', 'sku' => 'DET-001', 'is_sellable' => false]);
    $child = productForSupplier($this->store, $this->category, ['name' => 'Child', 'stock_source_product_id' => $candidate->id, 'stock_consumption_quantity' => '1.0000']);
    $inactive = productForSupplier($this->store, $this->category, ['name' => 'Inactivo', 'is_active' => false]);
    $purchases = Feature::query()->where('code', 'purchases')->firstOrFail();
    $foreignPlan = Plan::factory()->create();
    $foreignPlan->features()->attach($purchases->id);
    $foreignStore = Store::factory()->create(['plan_id' => $foreignPlan->id]);
    $foreignProduct = productForSupplier($foreignStore, Category::factory()->belongsToStore($foreignStore)->create());
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);
    SupplierProduct::create([
        'store_id' => $this->store->id,
        'supplier_id' => $this->supplier->id,
        'product_id' => $associated->id,
        'supplier_code' => 'ASO-500',
        'supplier_product_name' => 'Cloro proveedor',
        'is_preferred' => true,
    ]);
    SupplierProduct::create([
        'store_id' => $this->store->id,
        'supplier_id' => $otherSupplier->id,
        'product_id' => $candidate->id,
        'supplier_code' => 'OTRO-999',
        'supplier_product_name' => 'Nombre de otro proveedor',
        'is_preferred' => true,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates?q=DET-001&per_page=1")
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.id', $candidate->id)
        ->assertJsonPath('data.items.0.is_sellable', false);

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates")
        ->assertOk();
    $ids = collect($response->json('data.items'))->pluck('id');
    expect($ids)->not->toContain($associated->id)
        ->and($ids)->toContain($candidate->id)
        ->and($ids)->not->toContain($child->id)
        ->and($ids)->not->toContain($inactive->id)
        ->and($ids)->not->toContain($foreignProduct->id);

    $allProducts = $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates?include_associated=1")
        ->assertOk();
    $associationStates = collect($allProducts->json('data.items'))->keyBy('id');
    expect($associationStates->get($associated->id)['is_associated'])->toBeTrue()
        ->and($associationStates->get($candidate->id)['is_associated'])->toBeFalse()
        ->and($associationStates->get($associated->id)['supplier_product']['id'])->toBeString()
        ->and($associationStates->get($associated->id)['supplier_product']['supplier_code'])->toBe('ASO-500')
        ->and($associationStates->get($associated->id)['supplier_product']['supplier_product_name'])->toBe('Cloro proveedor')
        ->and($associationStates->get($associated->id)['supplier_product']['is_preferred'])->toBeTrue()
        ->and($associationStates->get($candidate->id)['supplier_product'])->toBeNull()
        ->and($associationStates)->not->toHaveKey($child->id)
        ->and($associationStates)->not->toHaveKey($inactive->id);
});

test('protects bulk and candidates with the existing supplier permissions and purchases feature', function () {
    $product = productForSupplier($this->store, $this->category);
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_ADMIN');
    $viewOnly->givePermissionTo('suppliers.view');
    $editOnly = User::factory()->create(['store_id' => $this->store->id]);
    $editOnly->assignRole('STORE_ADMIN');
    $editOnly->givePermissionTo('suppliers.edit');

    $this->actingAs($editOnly, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates")->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products/bulk", ['product_ids' => [$product->id]])->assertForbidden();
});

test('filters candidates by an inactive source supplier without affecting the default behavior', function () {
    $source = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor origen', 'is_active' => false]);
    $other = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);
    $fromSource = productForSupplier($this->store, $this->category, ['name' => 'Cloro origen', 'sku' => 'ORI-001', 'is_sellable' => false]);
    $fromOther = productForSupplier($this->store, $this->category, ['name' => 'Cloro otro', 'sku' => 'OTR-001']);
    $alreadyTarget = productForSupplier($this->store, $this->category, ['name' => 'Cloro destino', 'sku' => 'DST-001']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $source->id, 'product_id' => $fromSource->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $other->id, 'product_id' => $fromOther->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $source->id, 'product_id' => $alreadyTarget->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $alreadyTarget->id]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates?source_supplier_id={$source->id}&search=ORI")
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.id', $fromSource->id);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products/candidates?source_supplier_id={$this->supplier->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_supplier_id');
});

test('bulk can make the target preferred while retaining source associations', function () {
    $source = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor origen']);
    $other = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor preferido actual']);
    $newTarget = productForSupplier($this->store, $this->category);
    $existingFalse = productForSupplier($this->store, $this->category);
    $existingTrue = productForSupplier($this->store, $this->category);

    foreach ([$newTarget, $existingFalse, $existingTrue] as $product) {
        SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $source->id, 'product_id' => $product->id]);
        SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $other->id, 'product_id' => $product->id, 'is_preferred' => true]);
    }
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $existingFalse->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $existingTrue->id, 'is_preferred' => true]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products/bulk", [
            'product_ids' => [$newTarget->id, $existingFalse->id, $existingTrue->id],
            'mark_as_preferred' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 1)
        ->assertJsonPath('data.preferred_updated_count', 1)
        ->assertJsonPath('data.skipped_existing_count', 1);

    foreach ([$newTarget, $existingFalse, $existingTrue] as $product) {
        expect(SupplierProduct::query()->where('product_id', $product->id)->where('supplier_id', $source->id)->exists())->toBeTrue()
            ->and(SupplierProduct::query()->where('product_id', $product->id)->where('supplier_id', $this->supplier->id)->where('is_preferred', true)->exists())->toBeTrue()
            ->and(SupplierProduct::query()->where('product_id', $product->id)->where('is_preferred', true)->count())->toBe(1);
    }
});
