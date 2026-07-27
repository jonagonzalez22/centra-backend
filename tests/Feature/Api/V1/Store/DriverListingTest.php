<?php

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
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    Permission::create(['name' => 'drivers.view', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('drivers.view');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('lists only STORE_DRIVER users in the store', function () {
    // Create 3 drivers and 2 non-drivers in the store
    $driver1 = User::factory()->create(['store_id' => $this->store->id]);
    $driver1->assignRole('STORE_DRIVER');

    $driver2 = User::factory()->create(['store_id' => $this->store->id]);
    $driver2->assignRole('STORE_DRIVER');

    $driver3 = User::factory()->create(['store_id' => $this->store->id]);
    $driver3->assignRole('STORE_DRIVER');

    $nonDriver1 = User::factory()->create(['store_id' => $this->store->id]);
    $nonDriver1->assignRole('STORE_ADMIN');

    $nonDriver2 = User::factory()->create(['store_id' => $this->store->id]);
    // no roles

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/drivers');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.total', 3);
});

test('excludes drivers from other stores', function () {
    $driverInStore = User::factory()->create(['store_id' => $this->store->id]);
    $driverInStore->assignRole('STORE_DRIVER');

    $otherStore = Store::factory()->create();
    $plan = Plan::factory()->create();
    $otherStore->update(['plan_id' => $plan->id]);
    $driverOtherStore = User::factory()->create(['store_id' => $otherStore->id]);
    $driverOtherStore->assignRole('STORE_DRIVER');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/drivers');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.total', 1);
});

test('user with multiple roles appears as driver', function () {
    $multiRoleUser = User::factory()->create(['store_id' => $this->store->id]);
    $multiRoleUser->assignRole('STORE_ADMIN');
    $multiRoleUser->assignRole('STORE_DRIVER');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/drivers');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.total', 1);
});
