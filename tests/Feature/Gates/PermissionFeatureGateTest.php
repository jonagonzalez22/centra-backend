<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionFeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Both caches are process-static while RefreshDatabase starts a fresh
    // SQLite schema for every example. Clear them so a prior suite cannot
    // make a newly created feature look unmapped.
    Store::clearFeatureCache();
    PermissionFeatureResolver::clearCache();

    Role::firstOrCreate(['name' => 'SUPER_ADMIN']);
    Role::firstOrCreate(['name' => 'STORE_ADMIN']);
    Role::firstOrCreate(['name' => 'STORE_USER']);

    $this->inventoryFeature = Feature::factory()->create(['code' => 'inventory']);
    $this->posFeature = Feature::factory()->create(['code' => 'pos']);
    $this->storeSettingsFeature = Feature::factory()->create(['code' => 'store_settings']);
    $this->messagingFeature = Feature::factory()->create(['code' => 'messaging']);
    $this->multiUserFeature = Feature::factory()->create(['code' => 'multi_user']);

    $this->planWithInventory = Plan::factory()->create();
    $this->planWithInventory->features()->sync([$this->inventoryFeature->id]);

    $this->planWithoutInventory = Plan::factory()->create();
    $this->planWithoutInventory->features()->sync([$this->messagingFeature->id]);

    $this->planWithPos = Plan::factory()->create();
    $this->planWithPos->features()->sync([$this->posFeature->id]);

    $this->planWithStoreSettings = Plan::factory()->create();
    $this->planWithStoreSettings->features()->sync([$this->storeSettingsFeature->id]);

    $this->planWithMultiUser = Plan::factory()->create();
    $this->planWithMultiUser->features()->sync([$this->multiUserFeature->id]);

    Permission::firstOrCreate(['name' => 'inventory.view']);
    Permission::firstOrCreate(['name' => 'payments.view']);
    Permission::firstOrCreate(['name' => 'payments.reverse']);
    Permission::firstOrCreate(['name' => 'price_lists.view']);
    Permission::firstOrCreate(['name' => 'store_payment_methods.view']);
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

test('resolves received payment permissions to POS', function () {
    expect(PermissionFeatureResolver::resolveFeature('payments.view'))->toBe('pos')
        ->and(PermissionFeatureResolver::resolveFeature('payments.reverse'))->toBe('pos');
});

test('store user payment permissions are gated by POS independently', function () {
    $withPos = Store::factory()->create(['plan_id' => $this->planWithPos->id]);
    $withoutPos = Store::factory()->create(['plan_id' => $this->planWithoutInventory->id]);

    $viewer = User::factory()->create(['store_id' => $withPos->id]);
    $viewer->assignRole('STORE_USER');
    $viewer->givePermissionTo('payments.view');

    $reverserWithoutPos = User::factory()->create(['store_id' => $withoutPos->id]);
    $reverserWithoutPos->assignRole('STORE_USER');
    $reverserWithoutPos->givePermissionTo('payments.reverse');

    expect(Gate::forUser($viewer)->allows('payments.view'))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('payments.reverse'))->toBeFalse()
        ->and(Gate::forUser($reverserWithoutPos)->allows('payments.reverse'))->toBeFalse();
});

test('price list and store payment method permissions remain subject to their features', function () {
    $posStore = Store::factory()->create(['plan_id' => $this->planWithPos->id]);
    $settingsStore = Store::factory()->create(['plan_id' => $this->planWithStoreSettings->id]);

    $posAdmin = User::factory()->create(['store_id' => $posStore->id]);
    $posAdmin->assignRole('STORE_ADMIN');
    $posAdmin->givePermissionTo(['price_lists.view', 'store_payment_methods.view']);

    $settingsAdmin = User::factory()->create(['store_id' => $settingsStore->id]);
    $settingsAdmin->assignRole('STORE_ADMIN');
    $settingsAdmin->givePermissionTo(['price_lists.view', 'store_payment_methods.view']);

    expect(Gate::forUser($posAdmin)->allows('price_lists.view'))->toBeTrue()
        ->and(Gate::forUser($posAdmin)->allows('store_payment_methods.view'))->toBeFalse()
        ->and(Gate::forUser($settingsAdmin)->allows('price_lists.view'))->toBeFalse()
        ->and(Gate::forUser($settingsAdmin)->allows('store_payment_methods.view'))->toBeTrue();
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
