<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);

    // Create permissions for the tests
    foreach (['vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete', 'drivers.view'] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Feature Flag ────────────────────────────────────────────────────

test('returns 403 when store does not have deliveries feature', function () {
    $storeWithoutFeature = Store::factory()->create();
    // No plan → no feature
    $userWithoutFeature = User::factory()->create(['store_id' => $storeWithoutFeature->id]);
    $userWithoutFeature->assignRole('STORE_ADMIN');
    $token = $userWithoutFeature->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/vehicles');

    $response->assertStatus(403);
});

// ── Permission Gates ────────────────────────────────────────────────

test('returns 403 when user lacks vehicles.view permission', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/vehicles');

    $response->assertStatus(403);
});

test('returns 403 when user lacks vehicles.create permission', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', [
            'name' => 'Test',
            'plate' => 'TST001',
            'type' => 'auto',
        ]);

    $response->assertStatus(403);
});

test('returns 403 when user lacks vehicles.edit permission', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/vehicles/{$vehicle->id}", [
            'name' => 'Updated',
            'plate' => $vehicle->plate,
            'type' => $vehicle->type,
        ]);

    $response->assertStatus(403);
});

test('returns 403 when user lacks vehicles.delete permission', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/vehicles/{$vehicle->id}");

    $response->assertStatus(403);
});

test('returns 403 when user lacks vehicles.edit for toggle', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
            'inactivation_reason' => 'maintenance',
        ]);

    $response->assertStatus(403);
});

test('returns 403 when user lacks drivers.view permission', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/drivers');

    $response->assertStatus(403);
});

// ── Permission Granted ──────────────────────────────────────────────

test('allows access when user has the correct permission', function () {
    $this->user->givePermissionTo('vehicles.view');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/vehicles');

    $response->assertStatus(200);
});

test('allows create when user has vehicles.create permission', function () {
    $this->user->givePermissionTo('vehicles.create');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', [
            'name' => 'Valid Create',
            'plate' => 'VAL001',
            'type' => 'auto',
        ]);

    $response->assertStatus(201);
});
