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
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['purchases.view', 'purchases.edit'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['purchases.view', 'purchases.edit']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->supplier = Supplier::query()->create(['store_id' => $this->store->id, 'business_name' => 'Proveedor']);
    $this->product = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id]);
    SupplierProduct::query()->create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $this->product->id]);
    $this->makePurchase = function (string $status = 'ordered', ?Store $store = null, ?Supplier $supplier = null): Purchase {
        $store ??= $this->store;
        $supplier ??= $this->supplier;
        $purchase = Purchase::query()->create([
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'OC-'.str_pad((string) (Purchase::count() + 1), 6, '0', STR_PAD_LEFT),
            'purchase_type' => 'order',
            'status' => $status,
            'supplier_business_name' => $supplier->business_name,
            'total' => '12.50',
            'created_by' => $this->user->id,
        ]);
        if ($store->id === $this->store->id) {
            PurchaseItem::query()->create([
                'purchase_id' => $purchase->id,
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'product_sku' => $this->product->sku,
                'measurement_unit_code' => 'unit',
                'measurement_unit_symbol' => 'u',
                'quantity_ordered' => '1.0000',
                'quantity_received' => '0.0000',
                'unit_price' => '12.50',
                'subtotal' => '12.50',
            ]);
        }

        return $purchase;
    };
});

function purchaseDocumentPayload(array $overrides = []): array
{
    return [...[
        'type' => 'invoice',
        'number' => '  A 0001-00001234  ',
        'issued_at' => '2026-10-23',
        'total_amount' => '125000.50',
        'notes' => 'Documento proveedor',
    ], ...$overrides];
}

test('creates and lists multiple commercial documents with exact metadata', function () {
    $purchase = ($this->makePurchase)('ordered');
    $first = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload())
        ->assertCreated()
        ->assertJsonPath('data.number', 'A 0001-00001234')
        ->assertJsonPath('data.total_amount', '125000.50')
        ->assertJsonPath('data.created_by.name', $this->user->name);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload([
            'type' => 'delivery_note',
            'number' => '0003-00004567',
            'total_amount' => null,
            'notes' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.total_amount', null)
        ->assertJsonPath('data.notes', null);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/purchases/{$purchase->id}/documents")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(PurchaseDocument::query()->where('purchase_id', $purchase->id)->count())->toBe(2)
        ->and($first->json('data.id'))->not->toBeEmpty();
});

test('validates required and strictly positive amounts by document type', function () {
    $purchase = ($this->makePurchase)('ordered');

    foreach (['invoice', 'credit_note', 'debit_note'] as $type) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => '0.00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => '-0.01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => '0.01']))
            ->assertCreated();
    }

    foreach (['delivery_note', 'other'] as $type) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => null]))
            ->assertCreated();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => '125000.50']))
            ->assertCreated();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['type' => $type, 'total_amount' => '0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_amount');
    }

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload(['issued_at' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors('issued_at');
});

test('creates documents only for eligible lifecycle states and cancelled documents are read only', function () {
    foreach (['ordered', 'partially_received', 'received', 'closed'] as $status) {
        $purchase = ($this->makePurchase)($status);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload())
            ->assertCreated();
    }

    foreach (['draft', 'cancelled'] as $status) {
        $purchase = ($this->makePurchase)($status);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload())
            ->assertUnprocessable();
    }

    $cancelled = ($this->makePurchase)('ordered');
    $document = PurchaseDocument::query()->create([
        'store_id' => $this->store->id,
        'purchase_id' => $cancelled->id,
        ...purchaseDocumentPayload(['number' => 'FC-A-1234']),
        'created_by' => $this->user->id,
    ]);
    $cancelled->update(['status' => 'cancelled']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/purchases/{$cancelled->id}/documents")
        ->assertOk()->assertJsonPath('data.0.number', 'FC-A-1234');
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/purchases/{$cancelled->id}/documents/{$document->id}", purchaseDocumentPayload())
        ->assertUnprocessable();
    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/purchases/{$cancelled->id}/documents/{$document->id}")
        ->assertUnprocessable();
});

test('updates conditional amount rules and deletes documents for an active purchase', function () {
    $purchase = ($this->makePurchase)('received');
    $document = PurchaseDocument::query()->create([
        'store_id' => $this->store->id,
        'purchase_id' => $purchase->id,
        'type' => 'delivery_note',
        'number' => 'REM-1',
        'issued_at' => '2026-10-22',
        'created_by' => $this->user->id,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/purchases/{$purchase->id}/documents/{$document->id}", purchaseDocumentPayload(['type' => 'invoice', 'total_amount' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors('total_amount');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/purchases/{$purchase->id}/documents/{$document->id}", purchaseDocumentPayload(['type' => 'invoice', 'total_amount' => '0.00']))
        ->assertUnprocessable()->assertJsonValidationErrors('total_amount');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/purchases/{$purchase->id}/documents/{$document->id}", purchaseDocumentPayload(['type' => 'invoice', 'total_amount' => '0.01']))
        ->assertOk()->assertJsonPath('data.total_amount', '0.01');

    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/purchases/{$purchase->id}/documents/{$document->id}")
        ->assertOk();
    expect(PurchaseDocument::query()->whereKey($document->id)->exists())->toBeFalse();
});

test('scopes purchase and document ids to the authenticated store and parent purchase', function () {
    $purchase = ($this->makePurchase)('ordered');
    $document = PurchaseDocument::query()->create([
        'store_id' => $this->store->id,
        'purchase_id' => $purchase->id,
        ...purchaseDocumentPayload(),
        'created_by' => $this->user->id,
    ]);
    $otherPurchase = ($this->makePurchase)('ordered');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/purchases/{$otherPurchase->id}/documents/{$document->id}", purchaseDocumentPayload())
        ->assertNotFound();

    $otherStore = Store::factory()->create(['plan_id' => $this->store->plan_id]);
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo(['purchases.view', 'purchases.edit']);

    $this->actingAs($otherUser, 'sanctum')
        ->getJson("/api/v1/store/purchases/{$purchase->id}/documents")
        ->assertNotFound();
    $this->actingAs($otherUser, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload())
        ->assertNotFound();
});

test('uses purchases view for reading and purchases edit for document mutations', function () {
    $purchase = ($this->makePurchase)('ordered');
    $viewer = User::factory()->create(['store_id' => $this->store->id]);
    $viewer->assignRole('STORE_ADMIN');
    $viewer->givePermissionTo('purchases.view');

    $this->actingAs($viewer, 'sanctum')
        ->getJson("/api/v1/store/purchases/{$purchase->id}/documents")
        ->assertOk();
    $this->actingAs($viewer, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/documents", purchaseDocumentPayload())
        ->assertForbidden();
});
