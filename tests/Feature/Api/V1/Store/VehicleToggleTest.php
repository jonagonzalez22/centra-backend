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
    foreach (['vehicles.view', 'vehicles.edit'] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['vehicles.view', 'vehicles.edit']);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('deactivates vehicle with valid reason', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
            'inactivation_reason' => 'maintenance',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.inactivation_reason', 'maintenance');

    $vehicle->refresh();
    expect($vehicle->is_active)->toBeFalse();
    expect($vehicle->inactivation_reason)->toBe('maintenance');
});

test('activates vehicle and clears reason fields', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create([
        'is_active' => false,
        'inactivation_reason' => 'maintenance',
        'inactivation_notes' => 'Engine check',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => true,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.inactivation_reason', null)
        ->assertJsonPath('data.inactivation_notes', null);

    $vehicle->refresh();
    expect($vehicle->is_active)->toBeTrue();
    expect($vehicle->inactivation_reason)->toBeNull();
    expect($vehicle->inactivation_notes)->toBeNull();
});

test('requires inactivation_reason when deactivating', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('inactivation_reason');
});

test('requires inactivation_notes when reason is other', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
            'inactivation_reason' => 'other',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('inactivation_notes');
});

test('rejects invalid inactivation_reason code', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
            'inactivation_reason' => 'nonexistent_reason',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('inactivation_reason');
});

test('deactivate with other reason and notes succeeds', function () {
    $vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->patchJson("/api/v1/store/vehicles/{$vehicle->id}/toggle-active", [
            'is_active' => false,
            'inactivation_reason' => 'other',
            'inactivation_notes' => 'Vehicle sold to third party',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.inactivation_reason', 'other')
        ->assertJsonPath('data.inactivation_notes', 'Vehicle sold to third party');
});
