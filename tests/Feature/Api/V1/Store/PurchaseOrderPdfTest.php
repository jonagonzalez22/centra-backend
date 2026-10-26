<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDocument;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentNumberFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'purchases.view', 'guard_name' => 'web']);
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create([
        'plan_id' => $plan->id,
        'name' => 'CENTRA Norte',
        'cuit' => '30-12345678-9',
        'address' => 'Av. Siempre Viva 123',
        'phone' => '261 555 0100',
        'email' => 'compras@centra.test',
    ]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('purchases.view');
    $this->supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor histórico', 'tax_id' => '30-87654321-0']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
});

function pdfPurchase($test, string $status = 'draft', string $type = 'order'): Purchase
{
    $product = Product::factory()->forStore($test->store)->create(['category_id' => $test->category->id, 'name' => 'Nombre congelado', 'sku' => 'SKU-H-'.(Product::count() + 1)]);
    $purchase = Purchase::create([
        'store_id' => $test->store->id,
        'supplier_id' => $test->supplier->id,
        'purchase_number' => 'OC-000015',
        'purchase_type' => $type,
        'status' => $status,
        'supplier_business_name' => 'Proveedor congelado',
        'supplier_tax_id' => '30-99999999-9',
        'notes' => 'Entregar por la mañana',
        'total' => '12500.00',
        'ordered_at' => $status === 'draft' ? null : now()->subDay(),
        'created_by' => $test->user->id,
    ]);
    PurchaseItem::create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_name' => 'Producto congelado',
        'product_sku' => 'SKU-CONGELADO',
        'supplier_code' => 'DET-05',
        'supplier_product_name' => 'Descripción proveedor congelada',
        'measurement_unit_code' => 'unit',
        'measurement_unit_symbol' => 'u',
        'quantity_ordered' => '2.5000',
        'quantity_received' => '0.0000',
        'unit_price' => '5000.00',
        'subtotal' => '12500.00',
    ]);

    return $purchase;
}

test('downloads a draft purchase order PDF without changing the purchase', function () {
    $purchase = pdfPurchase($this);
    $before = $purchase->fresh()->getAttributes();

    $response = $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$purchase->id}/pdf");

    $response->assertOk()->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=OC-000015-BORRADOR.pdf');
    expect($response->getContent())->toStartWith('%PDF')
        ->and($purchase->fresh()->getAttributes())->toEqual($before);
});

test('downloads every supported issued order state and marks cancellation', function () {
    foreach (['ordered', 'partially_received', 'received', 'closed', 'cancelled'] as $status) {
        $purchase = pdfPurchase($this, $status);
        $purchase->update(['purchase_number' => 'OC-'.strtoupper($status)]);
        $response = $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$purchase->id}/pdf");
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->headers->get('content-disposition'))->toContain(
            $status === 'cancelled' ? 'OC-CANCELLED-CANCELADA.pdf' : 'OC-'.strtoupper($status).'.pdf'
        );
    }
});

test('uses exact document formatters for money and quantities', function () {
    expect(DocumentNumberFormatter::quantity('10.0000'))->toBe('10')
        ->and(DocumentNumberFormatter::quantity('2.5000'))->toBe('2,5')
        ->and(DocumentNumberFormatter::quantity('0.1250'))->toBe('0,125')
        ->and(DocumentNumberFormatter::quantityWithUnit('5.0000', 'unit', 'u'))->toBe('5 unid.')
        ->and(DocumentNumberFormatter::quantityWithUnit('2.5000', 'kg', 'kg'))->toBe('2,5 kg')
        ->and(DocumentNumberFormatter::money('12500.00'))->toBe('$ 12.500,00');
});

test('renders supplier-facing purchase snapshots without internal terminology or SKU', function () {
    $purchase = pdfPurchase($this, 'ordered')->load('items');
    $html = view('pdf.purchase-order', [
        'purchase' => $purchase,
        'store' => $this->store,
        'isDraft' => false,
        'isCancelled' => false,
        'relevantDate' => $purchase->ordered_at,
        'generatedAt' => now(),
    ])->render();

    expect($html)->toContain('Descripción proveedor congelada')
        ->toContain('DET-05')
        ->toContain('2,5 unid.')
        ->toContain('CUIT 30-99999999-9')
        ->toContain('Fecha de orden')
        ->toContain('$ 12.500,00')
        ->not->toContain('UOM')
        ->not->toContain('SKU-CONGELADO')
        ->not->toContain('Fecha de emisión');
});

test('only renders the historical supplier tax id when the purchase has one', function () {
    $purchase = pdfPurchase($this, 'ordered');
    $purchase->update(['supplier_tax_id' => null]);
    $html = view('pdf.purchase-order', ['purchase' => $purchase->fresh()->load('items'), 'store' => $this->store, 'isDraft' => false, 'isCancelled' => false, 'relevantDate' => $purchase->ordered_at, 'generatedAt' => now()])->render();

    expect($html)->not->toContain('30-87654321-0')->not->toContain('CUIT 30-99999999-9');
});

test('keeps draft and cancelled marks in the document template', function () {
    $purchase = pdfPurchase($this)->load('items');
    $draft = view('pdf.purchase-order', ['purchase' => $purchase, 'store' => $this->store, 'isDraft' => true, 'isCancelled' => false, 'relevantDate' => $purchase->created_at, 'generatedAt' => now()])->render();
    $cancelled = view('pdf.purchase-order', ['purchase' => $purchase, 'store' => $this->store, 'isDraft' => false, 'isCancelled' => true, 'relevantDate' => $purchase->created_at, 'generatedAt' => now()])->render();

    expect($draft)->toContain('BORRADOR')->toContain('Documento preliminar sujeto a confirmación.')
        ->and($cancelled)->toContain('CANCELADA');
});

test('does not mark a closed purchase as closed in the commercial document', function () {
    $purchase = pdfPurchase($this, 'closed')->load('items');
    $html = view('pdf.purchase-order', ['purchase' => $purchase, 'store' => $this->store, 'isDraft' => false, 'isCancelled' => false, 'relevantDate' => $purchase->ordered_at, 'generatedAt' => now()])->render();

    expect($html)->not->toContain('CERRADA');
});

test('generates a valid PDF for an order with many item rows', function () {
    $purchase = pdfPurchase($this, 'ordered');
    foreach (range(1, 18) as $number) {
        $product = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id, 'sku' => 'SKU-PDF-'.$number]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'Producto '.$number,
            'product_sku' => 'SKU-PDF-'.$number,
            'supplier_code' => 'SUP-'.$number,
            'measurement_unit_code' => 'kg',
            'measurement_unit_symbol' => 'kg',
            'quantity_ordered' => '1.0000',
            'quantity_received' => '0.0000',
            'unit_price' => '1.00',
            'subtotal' => '1.00',
        ]);
    }

    $response = $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$purchase->id}/pdf");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

test('rejects direct purchases, missing permissions, and another store purchase', function () {
    $direct = pdfPurchase($this, 'draft', 'direct');
    $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$direct->id}/pdf")->assertUnprocessable();

    $withoutPermission = User::factory()->create(['store_id' => $this->store->id]);
    $withoutPermission->assignRole('STORE_ADMIN');
    $this->actingAs($withoutPermission, 'sanctum')->get("/api/v1/store/purchases/{$direct->id}/pdf")->assertForbidden();

    $otherStore = Store::factory()->create(['plan_id' => $this->store->plan_id]);
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherSupplier = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Ajeno']);
    $foreign = Purchase::create(['store_id' => $otherStore->id, 'supplier_id' => $otherSupplier->id, 'purchase_number' => 'OC-000015', 'purchase_type' => 'order', 'status' => 'ordered', 'supplier_business_name' => 'Ajeno', 'total' => '0.00', 'created_by' => $otherUser->id]);
    $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$foreign->id}/pdf")->assertNotFound();
});

test('purchase documents do not affect the ordered total or PDF response', function () {
    $purchase = pdfPurchase($this, 'ordered');
    PurchaseDocument::create(['store_id' => $this->store->id, 'purchase_id' => $purchase->id, 'type' => 'invoice', 'number' => 'F-1', 'issued_at' => today(), 'total_amount' => '1.00', 'created_by' => $this->user->id]);

    $this->actingAs($this->user, 'sanctum')->get("/api/v1/store/purchases/{$purchase->id}/pdf")
        ->assertOk()->assertHeader('content-disposition', 'attachment; filename=OC-000015.pdf');
    expect($purchase->fresh()->total)->toBe('12500.00');
});
