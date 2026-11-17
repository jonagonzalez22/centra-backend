<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $storeAdminRole = Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Permission::findOrCreate('inventory.create', 'web');
    Permission::findOrCreate('suppliers.edit', 'web');
    $storeAdminRole->givePermissionTo(['inventory.create', 'suppliers.edit']);
    $inventory = Feature::firstOrCreate(['code' => 'inventory'], ['name' => 'Inventario']);
    $purchases = Feature::firstOrCreate(['code' => 'purchases'], ['name' => 'Compras']);
    $plan = Plan::factory()->create();
    $plan->features()->sync([$inventory->id, $purchases->id]);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->category = Category::factory()->belongsToStore($this->store)->create();
});

function productCreationPayload(Category $category, string $sku, array $suppliers = []): array
{
    return [
        'name' => 'Producto '.$sku,
        'sku' => $sku,
        'category_id' => $category->id,
        'price' => '100.00',
        'stock' => '0.0000',
        'stock_min' => '0.0000',
        'suppliers' => $suppliers,
    ];
}

test('creates a product without suppliers and keeps supplier associations optional', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productCreationPayload($this->category, 'WITHOUT-SUPPLIER'))
        ->assertCreated();

    $product = Product::query()->where('sku', 'WITHOUT-SUPPLIER')->firstOrFail();
    expect(SupplierProduct::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

test('requires purchases and suppliers edit only when creating supplier associations', function () {
    $supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor']);
    $payload = productCreationPayload($this->category, 'AUTH-SUPPLIER', [['supplier_id' => $supplier->id]]);

    $withoutEdit = User::factory()->create(['store_id' => $this->store->id]);
    $withoutEdit->assignRole('STORE_USER');
    $withoutEdit->givePermissionTo('inventory.create');
    $this->actingAs($withoutEdit, 'sanctum')->postJson('/api/v1/store/products', $payload)->assertForbidden();
    expect(Product::query()->where('sku', 'AUTH-SUPPLIER')->exists())->toBeFalse();

    $purchases = Feature::query()->where('code', 'purchases')->firstOrFail();
    $withoutPurchasesPlan = Plan::factory()->create();
    $withoutPurchasesPlan->features()->attach(Feature::query()->where('code', 'inventory')->firstOrFail()->id);
    $withoutPurchasesStore = Store::factory()->create(['plan_id' => $withoutPurchasesPlan->id]);
    $withoutPurchasesUser = User::factory()->create(['store_id' => $withoutPurchasesStore->id]);
    $withoutPurchasesUser->assignRole('STORE_USER');
    $withoutPurchasesUser->givePermissionTo(['inventory.create', 'suppliers.edit']);
    $category = Category::factory()->belongsToStore($withoutPurchasesStore)->create();
    $withoutPurchasesSupplier = Supplier::create(['store_id' => $withoutPurchasesStore->id, 'business_name' => 'Proveedor local']);
    $withoutPurchasesPayload = productCreationPayload($category, 'WITHOUT-PURCHASES', [['supplier_id' => $withoutPurchasesSupplier->id]]);
    $this->actingAs($withoutPurchasesUser, 'sanctum')->postJson('/api/v1/store/products', $withoutPurchasesPayload)->assertForbidden();
    expect(Product::query()->where('sku', 'WITHOUT-PURCHASES')->exists())->toBeFalse();
    expect($purchases)->not->toBeNull();
});

test('creates one or multiple initial supplier associations with their own metadata', function () {
    $first = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor uno']);
    $second = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor dos']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productCreationPayload($this->category, 'WITH-SUPPLIERS', [
            [
                'supplier_id' => $first->id,
                'supplier_code' => '  UNO-100  ',
                'supplier_product_name' => '  Nombre uno  ',
                'is_preferred' => true,
            ],
            [
                'supplier_id' => $second->id,
                'supplier_code' => null,
                'supplier_product_name' => null,
                'is_preferred' => false,
            ],
        ]))
        ->assertCreated();

    $product = Product::query()->where('sku', 'WITH-SUPPLIERS')->firstOrFail();
    $associations = SupplierProduct::query()->where('product_id', $product->id)->get()->keyBy('supplier_id');

    expect($associations)->toHaveCount(2)
        ->and($associations[$first->id]->store_id)->toBe($this->store->id)
        ->and($associations[$first->id]->supplier_code)->toBe('UNO-100')
        ->and($associations[$first->id]->supplier_product_name)->toBe('Nombre uno')
        ->and($associations[$first->id]->is_preferred)->toBeTrue()
        ->and($associations[$second->id]->supplier_code)->toBeNull()
        ->and($associations[$second->id]->supplier_product_name)->toBeNull()
        ->and($associations[$second->id]->is_preferred)->toBeFalse();
});

test('allows zero preferred suppliers and rejects more than one preferred supplier', function () {
    $first = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor uno']);
    $second = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor dos']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productCreationPayload($this->category, 'NO-PREFERRED', [
            ['supplier_id' => $first->id],
            ['supplier_id' => $second->id],
        ]))
        ->assertCreated();

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productCreationPayload($this->category, 'TWO-PREFERRED', [
            ['supplier_id' => $first->id, 'is_preferred' => true],
            ['supplier_id' => $second->id, 'is_preferred' => true],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('suppliers');

    expect(Product::query()->where('sku', 'TWO-PREFERRED')->exists())->toBeFalse();
});

test('rejects duplicate, inactive, or foreign suppliers without creating the product', function () {
    $supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor activo']);
    $inactive = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor inactivo', 'is_active' => false]);
    $otherStore = Store::factory()->create();
    $foreign = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Proveedor externo']);

    foreach ([
        'DUPLICATE-SUPPLIER' => [['supplier_id' => $supplier->id], ['supplier_id' => $supplier->id]],
        'INACTIVE-SUPPLIER' => [['supplier_id' => $inactive->id]],
        'FOREIGN-SUPPLIER' => [['supplier_id' => $foreign->id]],
    ] as $sku => $suppliers) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/store/products', productCreationPayload($this->category, $sku, $suppliers))
            ->assertUnprocessable();

        expect(Product::query()->where('sku', $sku)->exists())->toBeFalse();
    }
});

test('rejects a supplier association for a shared-stock child and allows an own-stock non-sellable product', function () {
    $supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor']);
    $source = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id]);

    $childPayload = productCreationPayload($this->category, 'SHARED-CHILD', [['supplier_id' => $supplier->id]]);
    $childPayload['stock_source_product_id'] = $source->id;
    $childPayload['stock_consumption_quantity'] = '1.0000';

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', $childPayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('suppliers');

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productCreationPayload($this->category, 'OWN-NON-SELLABLE', [['supplier_id' => $supplier->id]]) + ['is_sellable' => false])
        ->assertCreated();

    $product = Product::query()->where('sku', 'OWN-NON-SELLABLE')->firstOrFail();
    expect($product->is_sellable)->toBeFalse()
        ->and(SupplierProduct::query()->where('product_id', $product->id)->exists())->toBeTrue();
});
