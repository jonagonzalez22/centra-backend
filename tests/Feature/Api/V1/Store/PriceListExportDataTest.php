<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\MeasurementUnit;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'price_lists.export', 'guard_name' => 'web']);

    $feature = Feature::firstOrCreate(['code' => 'pos'], ['name' => 'Punto de Venta']);
    $plan = Plan::factory()->create();
    $plan->features()->sync([$feature->id]);

    $this->store = Store::factory()->create([
        'plan_id' => $plan->id,
        'name' => 'Comercial Centra',
        'url_logo' => 'https://cdn.example.test/logo.png',
        'phone' => '2615550101',
        'address' => 'Av. Central 123',
    ]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('price_lists.export');
    $this->category = Category::factory()->create(['store_id' => $this->store->id, 'name' => 'Bebidas']);
    $this->brand = Brand::factory()->create(['store_id' => $this->store->id, 'name' => 'Centra']);
    $this->liter = MeasurementUnit::query()->where('code', 'l')->firstOrFail();
});

function exportPriceList(User $user, array $payload): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/store/price-lists/export-data', $payload);
}

function exportProduct(object $test, array $attributes = []): Product
{
    return Product::factory()->forStore($test->store)->create([
        'category_id' => $test->category->id,
        'brand_id' => $test->brand->id,
        'is_active' => true,
        'is_sellable' => true,
        'stock' => '10.0000',
        'stock_reserved' => '0.0000',
        ...$attributes,
    ]);
}

function filteredExportPayload(PriceList $priceList, array $filters = []): array
{
    return [
        'price_list_id' => $priceList->id,
        'selection_mode' => 'filtered',
        'filters' => $filters,
    ];
}

test('exports active store price list data with exact unit prices, discount and store metadata', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista', 'discount_percentage' => '10.00']);
    $product = exportProduct($this, [
        'name' => 'Agua mineral',
        'sku' => 'AGUA-1500',
        'price' => '2500.00',
        'stock_measurement_unit_id' => $this->liter->id,
    ]);

    exportPriceList($this->user, filteredExportPayload($list))
        ->assertOk()
        ->assertJsonPath('data.store.name', 'Comercial Centra')
        ->assertJsonPath('data.store.logo_url', 'https://cdn.example.test/logo.png')
        ->assertJsonPath('data.pricing_mode', 'price_list')
        ->assertJsonPath('data.price_list.id', $list->id)
        ->assertJsonPath('data.price_list.discount_percentage', '10.00')
        ->assertJsonPath('data.products.0.id', $product->id)
        ->assertJsonPath('data.products.0.category.name', 'Bebidas')
        ->assertJsonPath('data.products.0.brand.name', 'Centra')
        ->assertJsonPath('data.products.0.measurement_unit.code', 'l')
        ->assertJsonPath('data.products.0.measurement_unit.name', 'Litro')
        ->assertJsonPath('data.products.0.list_price', '2500.00')
        ->assertJsonPath('data.products.0.discount_amount', '250.00')
        ->assertJsonPath('data.products.0.final_price', '2250.00')
        ->assertJsonPath('data.summary.product_count', 1);
});

test('exports general prices without a price list and keeps base and final prices equal', function () {
    $product = exportProduct($this, [
        'name' => 'Agua mineral',
        'price' => '10.05',
        'sale_quantity_step' => '0.2500',
    ]);

    exportPriceList($this->user, [
        'pricing_mode' => 'general',
        'selection_mode' => 'filtered',
        'filters' => ['include_out_of_stock' => true],
    ])
        ->assertOk()
        ->assertJsonPath('data.pricing_mode', 'general')
        ->assertJsonPath('data.price_list', null)
        ->assertJsonPath('data.products.0.id', $product->id)
        ->assertJsonPath('data.products.0.list_price', '10.05')
        ->assertJsonPath('data.products.0.discount_percentage', '0.00')
        ->assertJsonPath('data.products.0.discount_amount', '0.00')
        ->assertJsonPath('data.products.0.final_price', '10.05');
});

test('rejects inactive and foreign price lists without leaking them', function () {
    $inactive = PriceList::factory()->create(['store_id' => $this->store->id, 'is_active' => false]);
    $foreign = PriceList::factory()->create();

    exportPriceList($this->user, filteredExportPayload($inactive))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_list_id');
    exportPriceList($this->user, filteredExportPayload($foreign))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_list_id');
});

test('requires the export permission and POS feature', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id]);
    $withoutPermission = User::factory()->create(['store_id' => $this->store->id]);
    $withoutPermission->assignRole('STORE_ADMIN');

    exportPriceList($withoutPermission, filteredExportPayload($list))->assertForbidden();

    $withoutFeaturePlan = Plan::factory()->create();
    $withoutFeatureStore = Store::factory()->create(['plan_id' => $withoutFeaturePlan->id]);
    $withoutFeatureUser = User::factory()->create(['store_id' => $withoutFeatureStore->id]);
    $withoutFeatureUser->assignRole('STORE_ADMIN');
    $withoutFeatureUser->givePermissionTo('price_lists.export');
    $foreignList = PriceList::factory()->create(['store_id' => $withoutFeatureStore->id]);

    exportPriceList($withoutFeatureUser, filteredExportPayload($foreignList))->assertForbidden();
});

test('selected products are revalidated and report ineligible or foreign IDs safely', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id]);
    $eligible = exportProduct($this, ['name' => 'Elegible']);
    $disabled = exportProduct($this, ['name' => 'No vendible', 'is_sellable' => false]);
    $inactive = exportProduct($this, ['name' => 'Inactivo', 'is_active' => false]);
    $foreign = Product::factory()->create();

    exportPriceList($this->user, [
        'price_list_id' => $list->id,
        'selection_mode' => 'selected',
        'product_ids' => [$eligible->id, $disabled->id, $inactive->id, $foreign->id],
    ])
        ->assertOk()
        ->assertJsonPath('data.products.0.id', $eligible->id)
        ->assertJsonCount(3, 'data.excluded_product_ids')
        ->assertJsonPath('data.summary.excluded_product_count', 3);
});

test('filtered selection uses name SKU category and brand without pagination', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id]);
    $otherCategory = Category::factory()->create(['store_id' => $this->store->id]);
    $matching = exportProduct($this, ['name' => 'Jugo naranja', 'sku' => 'JUGO-001']);
    exportProduct($this, ['name' => 'Jugo manzana', 'sku' => 'JUGO-002', 'category_id' => $otherCategory->id]);
    exportProduct($this, ['name' => 'Aceite', 'sku' => 'ACEITE-002']);

    exportPriceList($this->user, filteredExportPayload($list, [
        'search' => '001',
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.id', $matching->id);
});

test('availability reuses reserved and shared-stock commercial quantity, with configurable out-of-stock inclusion', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id]);
    $reservedOut = exportProduct($this, ['name' => 'Reservado', 'stock' => '3.0000', 'stock_reserved' => '3.0000']);
    $source = exportProduct($this, ['name' => 'Tanque', 'is_sellable' => false, 'stock' => '10.0000', 'stock_reserved' => '5.0000']);
    $sharedAvailable = exportProduct($this, [
        'name' => 'Bidón', 'stock' => '0.0000', 'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '2.0000', 'sale_quantity_step' => '1.0000',
    ]);

    exportPriceList($this->user, filteredExportPayload($list))
        ->assertOk()
        ->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.products.0.id', $sharedAvailable->id)
        ->assertJsonPath('data.products.0.commercial_available_quantity', '2.0000');

    exportPriceList($this->user, filteredExportPayload($list, ['include_out_of_stock' => true]))
        ->assertOk()
        ->assertJsonCount(2, 'data.products')
        ->assertJsonFragment(['id' => $reservedOut->id]);
});

test('calculates zero, decimal and full discounts per published unit without using sale step', function () {
    $zero = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '0.00']);
    $ten = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '10.00']);
    $full = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '100.00']);
    $product = exportProduct($this, ['price' => '10.05', 'sale_quantity_step' => '0.2500']);

    exportPriceList($this->user, filteredExportPayload($zero))
        ->assertJsonPath('data.products.0.discount_amount', '0.00')
        ->assertJsonPath('data.products.0.final_price', '10.05');
    exportPriceList($this->user, filteredExportPayload($ten))
        ->assertJsonPath('data.products.0.discount_amount', '1.01')
        ->assertJsonPath('data.products.0.final_price', '9.04');
    exportPriceList($this->user, filteredExportPayload($full))
        ->assertJsonPath('data.products.0.final_price', '0.00');
});
