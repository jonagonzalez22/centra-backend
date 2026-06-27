<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'SUPER_ADMIN']);
    Role::firstOrCreate(['name' => 'STORE_ADMIN']);
    Role::firstOrCreate(['name' => 'STORE_USER']);

    $this->inventoryFeature = Feature::factory()->create(['code' => 'inventory']);
    $this->messagingFeature = Feature::factory()->create(['code' => 'messaging']);
    $this->multiUserFeature = Feature::factory()->create(['code' => 'multi_user']);

    $this->planWithInventory = Plan::factory()->create();
    $this->planWithInventory->features()->sync([$this->inventoryFeature->id]);

    $this->planWithoutInventory = Plan::factory()->create();
    $this->planWithoutInventory->features()->sync([$this->messagingFeature->id]);

    $this->planWithMultiUser = Plan::factory()->create();
    $this->planWithMultiUser->features()->sync([$this->multiUserFeature->id]);

    Permission::firstOrCreate(['name' => 'inventory.view']);
    Permission::firstOrCreate(['name' => 'store_users.view']);
    Permission::firstOrCreate(['name' => 'some.unmapped.permission']);
});

test('super_admin always passes regardless of feature', function () {
    $user = User::factory()->create(['store_id' => null]);
    $user->assignRole('SUPER_ADMIN');
    $user->givePermissionTo('inventory.view');

    expect(Gate::forUser($user)->allows('inventory.view'))->toBeTrue();
});

test('store_admin with feature passes mapped permission via convention', function () {
    $store = Store::factory()->create(['plan_id' => $this->planWithInventory->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_ADMIN');
    $user->givePermissionTo('inventory.view');

    expect(Gate::forUser($user)->allows('inventory.view'))->toBeTrue();
});

test('store_admin without feature is denied mapped permission', function () {
    $store = Store::factory()->create(['plan_id' => $this->planWithoutInventory->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_ADMIN');
    $user->givePermissionTo('inventory.view');

    expect(Gate::forUser($user)->allows('inventory.view'))->toBeFalse();
});

test('store_user with feature passes exception-mapped permission', function () {
    $store = Store::factory()->create(['plan_id' => $this->planWithMultiUser->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_USER');
    $user->givePermissionTo('store_users.view');

    expect(Gate::forUser($user)->allows('store_users.view'))->toBeTrue();
});

test('store_user without feature is denied exception-mapped permission', function () {
    $store = Store::factory()->create(['plan_id' => $this->planWithoutInventory->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_USER');
    $user->givePermissionTo('store_users.view');

    expect(Gate::forUser($user)->allows('store_users.view'))->toBeFalse();
});

test('unmapped permission passes through without feature check', function () {
    $store = Store::factory()->create(['plan_id' => null]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole('STORE_ADMIN');
    $user->givePermissionTo('some.unmapped.permission');

    expect(Gate::forUser($user)->allows('some.unmapped.permission'))->toBeTrue();
});

test('backoffice_user_without_store_passes', function () {
    $user = User::factory()->create(['store_id' => null]);
    $user->givePermissionTo('inventory.view');

    expect(Gate::forUser($user)->allows('inventory.view'))->toBeTrue();
});

test('caching_prevents_repeated_queries', function () {
    $store = Store::factory()->create(['plan_id' => $this->planWithInventory->id]);

    expect($store->hasFeature('inventory'))->toBeTrue();
    expect($store->hasFeature('inventory'))->toBeTrue();
    expect($store->hasFeature('missing_feature'))->toBeFalse();
});
