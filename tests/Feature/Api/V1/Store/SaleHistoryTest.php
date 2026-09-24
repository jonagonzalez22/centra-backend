<?php

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['sales_history.view', 'sales_history.print', 'sales.cancel'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $plan->id]);
    $plan->features()->attach(Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']));
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['sales_history.view', 'sales_history.print']);
});

function saleHistoryOperation(Store $store, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => User::factory()->create(['store_id' => $store->id])->id,
        'type' => 'sale',
        'status' => 'confirmed',
    ], $attributes));
}

test('sales history is permission and store scoped and filters by operation number', function () {
    $sale = saleHistoryOperation($this->store, ['operation_number' => 'V-000028']);
    $other = saleHistoryOperation(Store::factory()->create(), ['operation_number' => 'V-000028']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales?operation_number=V-0000')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $sale->id)
        ->assertJsonMissing(['id' => $other->id]);
});

test('sales receipt requires sales_history.print without changing the shared operation receipt', function () {
    $sale = saleHistoryOperation($this->store);
    $this->user->revokePermissionTo('sales_history.print');

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/sales/{$sale->id}/receipt")
        ->assertForbidden();
});

test('sales history requires sales_history.view', function () {
    $this->user->revokePermissionTo('sales_history.view');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales')
        ->assertForbidden();
});

test('sales.cancel alone does not grant access to sales history', function () {
    $this->user->syncPermissions(['sales.cancel']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales')
        ->assertForbidden();
});

test('confirmed sale detail keeps an empty cancellation history and its creation data', function () {
    $sale = saleHistoryOperation($this->store, ['operation_number' => 'V-000029']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/sales/{$sale->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $sale->id)
        ->assertJsonPath('data.created_by.id', $sale->user_id)
        ->assertJsonPath('data.history', []);
});

test('cancelled sale detail exposes its cancellation event with the actor and reason data', function () {
    $sale = saleHistoryOperation($this->store, ['status' => 'cancelled']);
    $actor = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Juan Pérez']);
    $event = CommercialOperationEvent::create([
        'store_id' => $this->store->id,
        'operation_id' => $sale->id,
        'event_type' => 'sale_cancelled',
        'previous_status' => 'confirmed',
        'new_status' => 'cancelled',
        'reason' => 'pricing_error',
        'reason_code' => 'pricing_error',
        'reason_note' => 'Importe cargado incorrectamente',
        'metadata' => [],
        'user_id' => $actor->id,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/sales/{$sale->id}")
        ->assertOk()
        ->assertJsonPath('data.history.0.id', $event->id)
        ->assertJsonPath('data.history.0.event_type', 'sale_cancelled')
        ->assertJsonPath('data.history.0.previous_status', 'confirmed')
        ->assertJsonPath('data.history.0.new_status', 'cancelled')
        ->assertJsonPath('data.history.0.reason_code', 'pricing_error')
        ->assertJsonPath('data.history.0.reason_note', 'Importe cargado incorrectamente')
        ->assertJsonPath('data.history.0.user.id', $actor->id)
        ->assertJsonPath('data.history.0.user.name', 'Juan Pérez')
        ->assertJsonPath('data.history.0.created_at', $event->created_at->format('Y-m-d H:i:s'));
});

test('sale detail history remains store scoped', function () {
    $otherStore = Store::factory()->create();
    $sale = saleHistoryOperation($otherStore, ['status' => 'cancelled']);

    CommercialOperationEvent::create([
        'store_id' => $otherStore->id,
        'operation_id' => $sale->id,
        'event_type' => 'sale_cancelled',
        'reason' => 'pricing_error',
        'reason_code' => 'pricing_error',
        'metadata' => [],
        'user_id' => User::factory()->create(['store_id' => $otherStore->id])->id,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/sales/{$sale->id}")
        ->assertNotFound();
});

test('sales history defaults to newest sales first and supports created at sorting', function () {
    $older = saleHistoryOperation($this->store, [
        'operation_number' => 'V-SORT-001',
        'created_at' => now()->subDay(),
    ]);
    $newer = saleHistoryOperation($this->store, [
        'operation_number' => 'V-SORT-002',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $newer->id);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales?sort_by=created_at&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $older->id);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales?sort_by=created_at&sort_direction=desc')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $newer->id);
});

test('sales history combines filters, sorting, pagination and safely defaults an invalid direction', function () {
    $oldMatch = saleHistoryOperation($this->store, [
        'operation_number' => 'V-FILTER-001',
        'status' => 'confirmed',
        'created_at' => now()->subDay(),
    ]);
    $newMatch = saleHistoryOperation($this->store, [
        'operation_number' => 'V-FILTER-002',
        'status' => 'confirmed',
        'created_at' => now(),
    ]);
    saleHistoryOperation($this->store, ['operation_number' => 'V-OTHER-001', 'status' => 'cancelled']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales?operation_number=V-FILTER&status=confirmed&sort_by=created_at&sort_direction=asc&per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.current_page', 2)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.items.0.id', $newMatch->id);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/sales?operation_number=V-FILTER&sort_direction=invalid')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $newMatch->id);
});
