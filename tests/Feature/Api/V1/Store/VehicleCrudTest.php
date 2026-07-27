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

    // Create vehicle permissions
    foreach (['vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete'] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete']);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Create ──────────────────────────────────────────────────────────

test('creates a new vehicle', function () {
    $data = [
        'name' => 'Furgoneta Blanca',
        'plate' => 'ABC123',
        'type' => 'camioneta',
        'capacity_kg' => 1500,
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Furgoneta Blanca')
        ->assertJsonPath('data.plate', 'ABC123')
        ->assertJsonPath('data.type', 'camioneta')
        ->assertJsonPath('data.capacity_kg', 1500)
        ->assertJsonPath('data.is_active', true);

    expect(Vehicle::count())->toBe(1);
    expect(Vehicle::first()->store_id)->toBe($this->store->id);
});

test('rejects duplicate plate in the same store', function () {
    Vehicle::factory()->forStore($this->store)->create(['plate' => 'DUP001']);

    $data = [
        'name' => 'Other Vehicle',
        'plate' => 'DUP001',
        'type' => 'auto',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('plate');
});

test('allows same plate in different stores', function () {
    Vehicle::factory()->forStore($this->store)->create(['plate' => 'SAME001']);

    $otherStore = Store::factory()->create();
    $plan = Plan::factory()->create();
    $plan->features()->attach(Feature::where('code', 'deliveries')->first());
    $otherStore->update(['plan_id' => $plan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo(['vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete']);
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'Valid Duplicate Plate',
        'plate' => 'SAME001',
        'type' => 'auto',
    ];

    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson('/api/v1/store/vehicles', $data);

    $response->assertStatus(201);
    expect(Vehicle::where('store_id', $otherStore->id)->count())->toBe(1);
});

test('rejects invalid vehicle type', function () {
    $data = [
        'name' => 'Helicopter',
        'plate' => 'FLY001',
        'type' => 'helicoptero',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', $data);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

test('accepts null capacity', function () {
    $data = [
        'name' => 'Bici Reparto',
        'plate' => 'BIK001',
        'type' => 'bicicleta',
    ];

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/vehicles', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.capacity_kg', null);
});

// ── List ────────────────────────────────────────────────────────────

test('lists vehicles scoped to store', function () {
    Vehicle::factory()->forStore($this->store)->count(3)->create();

    $otherStore = Store::factory()->create();
    Vehicle::factory()->forStore($otherStore)->count(2)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/vehicles');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.total', 3);
});

// ── Show ────────────────────────────────────────────────────────────

test('shows a single vehicle', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create([
        'name' => 'My Vehicle',
        'plate' => 'SHW001',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/vehicles/{$vehicle->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'My Vehicle')
        ->assertJsonPath('data.plate', 'SHW001');
});

test('returns 404 for vehicle from another store', function () {
    $otherStore = Store::factory()->create();
    $otherVehicle = Vehicle::factory()->forStore($otherStore)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/vehicles/{$otherVehicle->id}");

    $response->assertStatus(404);
});

// ── Update ──────────────────────────────────────────────────────────

test('updates a vehicle', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create([
        'name' => 'Old Name',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/vehicles/{$vehicle->id}", [
            'name' => 'New Name',
            'plate' => $vehicle->plate,
            'type' => $vehicle->type,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'New Name');

    expect($vehicle->fresh()->name)->toBe('New Name');
});

// ── Destroy ─────────────────────────────────────────────────────────

test('deletes a vehicle', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/vehicles/{$vehicle->id}");

    $response->assertStatus(200);

    expect(Vehicle::find($vehicle->id))->toBeNull();
});

test('returns 404 when deleting vehicle from another store', function () {
    $otherStore = Store::factory()->create();
    $otherVehicle = Vehicle::factory()->forStore($otherStore)->create();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/vehicles/{$otherVehicle->id}");

    $response->assertStatus(404);
    expect(Vehicle::find($otherVehicle->id))->not->toBeNull();
});
