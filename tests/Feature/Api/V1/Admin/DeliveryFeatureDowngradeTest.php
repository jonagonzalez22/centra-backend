<?php

use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RouteManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $role = Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'stores.edit', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $this->admin = User::factory()->create();
    $this->admin->assignRole($role);
    $this->token = $this->admin->createToken('delivery-feature-admin')->plainTextToken;

    $this->deliveries = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $this->otherFeature = Feature::create(['code' => 'reports', 'name' => 'Reportes']);
    $this->withDeliveries = Plan::factory()->create();
    $this->withDeliveries->features()->sync([$this->deliveries->id]);
    $this->withoutDeliveries = Plan::factory()->create();
});

function deliveryFeatureStore(Plan $plan): Store
{
    return Store::factory()->create(['plan_id' => $plan->id]);
}

function deliveryFeatureRoute(Store $store, string $status): DeliveryRoute
{
    $vehicle = Vehicle::factory()->forStore($store)->create();
    $driver = User::factory()->create(['store_id' => $store->id]);

    return DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => $status,
    ]);
}

function deliveryFeatureStorePayload(Store $store, ?string $planId, string $name = 'Tienda actualizada'): array
{
    return [
        'name' => $name,
        'business_type_id' => $store->business_type_id,
        'plan_id' => $planId,
        'cuit' => '20345678906',
        'address' => 'Av. Corrientes 1234',
        'state' => 'Buenos Aires',
        'city' => 'Buenos Aires',
        'country' => 'Argentina',
        'phone' => '+541112345678',
        'email' => $store->email,
        'is_active' => true,
    ];
}

dataset('activeDeliveryRouteStatuses', [
    'draft' => ['draft'],
    'planned' => ['planned'],
    'loaded' => ['loaded'],
    'dispatched' => ['dispatched'],
    'awaiting reconciliation' => ['awaiting_reconciliation'],
]);

test('individual plan downgrade is rejected for every active route state', function (string $status) {
    $store = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($store, $status);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/admin/stores/{$store->id}", deliveryFeatureStorePayload($store, $this->withoutDeliveries->id, 'Cambio bloqueado'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.deliveries.0', 'Existen rutas pendientes que requieren la funcionalidad Entregas.');

    $store->refresh();
    expect($store->plan_id)->toBe($this->withDeliveries->id)
        ->and($store->name)->not->toBe('Cambio bloqueado');
})->with('activeDeliveryRouteStatuses');

test('individual plan downgrade is allowed once routes are terminal', function (string $status) {
    $store = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($store, $status);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/admin/stores/{$store->id}", deliveryFeatureStorePayload($store, $this->withoutDeliveries->id))
        ->assertOk();

    expect($store->fresh()->plan_id)->toBe($this->withoutDeliveries->id);
})->with([
    'completed' => ['completed'],
    'cancelled' => ['cancelled'],
]);

test('individual plan change that retains deliveries is allowed with active routes', function () {
    $store = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($store, 'dispatched');
    $replacementPlan = Plan::factory()->create();
    $replacementPlan->features()->sync([$this->deliveries->id]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/admin/stores/{$store->id}", deliveryFeatureStorePayload($store, $replacementPlan->id))
        ->assertOk();

    expect($store->fresh()->plan_id)->toBe($replacementPlan->id);
});

test('shared plan feature removal is atomic when one affected store has an active route', function () {
    $blockedStore = deliveryFeatureStore($this->withDeliveries);
    $safeStore = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($blockedStore, 'awaiting_reconciliation');
    $this->withDeliveries->features()->sync([$this->deliveries->id, $this->otherFeature->id]);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/plans/{$this->withDeliveries->id}/sync-features", [
            'features' => [['feature_id' => $this->otherFeature->id]],
        ])
        ->assertUnprocessable();

    expect($this->withDeliveries->fresh()->features()->whereKey($this->deliveries->id)->exists())->toBeTrue()
        ->and($this->withDeliveries->fresh()->features()->whereKey($this->otherFeature->id)->exists())->toBeTrue()
        ->and($safeStore->fresh()->plan_id)->toBe($this->withDeliveries->id);
});

test('shared plan feature removal is allowed when every affected store is terminal', function () {
    $completedStore = deliveryFeatureStore($this->withDeliveries);
    $cancelledStore = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($completedStore, 'completed');
    deliveryFeatureRoute($cancelledStore, 'cancelled');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/plans/{$this->withDeliveries->id}/sync-features", [
            'features' => [['feature_id' => $this->otherFeature->id]],
        ])
        ->assertOk();

    expect($this->withDeliveries->fresh()->features()->whereKey($this->deliveries->id)->exists())->toBeFalse()
        ->and($this->withDeliveries->fresh()->features()->whereKey($this->otherFeature->id)->exists())->toBeTrue();
});

test('shared plan can update unrelated features while retaining deliveries and active routes', function () {
    $store = deliveryFeatureStore($this->withDeliveries);
    deliveryFeatureRoute($store, 'dispatched');

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/plans/{$this->withDeliveries->id}/sync-features", [
            'features' => [
                ['feature_id' => $this->deliveries->id],
                ['feature_id' => $this->otherFeature->id],
            ],
        ])
        ->assertOk();

    expect($this->withDeliveries->fresh()->features()->whereKey($this->deliveries->id)->exists())->toBeTrue()
        ->and($this->withDeliveries->fresh()->features()->whereKey($this->otherFeature->id)->exists())->toBeTrue();
});

test('route writers recheck deliveries while holding the store lock', function () {
    $store = deliveryFeatureStore($this->withoutDeliveries);
    $vehicle = Vehicle::factory()->forStore($store)->create();
    $driver = User::factory()->create(['store_id' => $store->id]);
    Role::firstOrCreate(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);
    $driver->assignRole('STORE_DRIVER');

    app(RouteManagementService::class)->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->toDateString(),
    ], $store->id, $this->admin);
})->throws(\Illuminate\Http\Exceptions\HttpResponseException::class);
