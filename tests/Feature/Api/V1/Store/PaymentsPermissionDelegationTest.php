<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionFeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Store::clearFeatureCache();
    PermissionFeatureResolver::clearCache();

    foreach (['STORE_ADMIN', 'STORE_USER', 'STORE_DRIVER'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    foreach (['store_users.view', 'store_users.edit', 'payments.view', 'payments.reverse'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->pos = Feature::create(['code' => 'pos', 'name' => 'Punto de Venta']);
    $this->multiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario']);
});

function paymentPermissionContext(bool $withPos): array
{
    $plan = Plan::factory()->create();
    $features = [test()->multiUser->id];
    if ($withPos) {
        $features[] = test()->pos->id;
    }
    $plan->features()->sync($features);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $employee = User::factory()->create(['store_id' => $store->id]);
    $employee->assignRole('STORE_USER');

    return [$store, $admin, $employee];
}

test('catalog exposes received payment permissions only when POS is enabled', function (): void {
    [, $admin] = paymentPermissionContext(true);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/store/permissions/catalog')
        ->assertOk()
        ->assertJsonFragment(['name' => 'payments.view', 'label' => 'Ver pagos recibidos'])
        ->assertJsonFragment(['name' => 'payments.reverse', 'label' => 'Anular pagos recibidos']);

    [, $adminWithoutPos] = paymentPermissionContext(false);

    $this->actingAs($adminWithoutPos, 'sanctum')
        ->getJson('/api/v1/store/permissions/catalog')
        ->assertOk()
        ->assertJsonMissing(['name' => 'payments.view'])
        ->assertJsonMissing(['name' => 'payments.reverse']);
});

test('store admin delegates received payment permissions individually when POS is enabled', function (): void {
    [, $admin, $viewer] = paymentPermissionContext(true);
    $reverser = User::factory()->create(['store_id' => $admin->store_id]);
    $reverser->assignRole('STORE_USER');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/store/users/{$viewer->id}/permissions", ['permissions' => ['payments.view']])
        ->assertOk();
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/store/users/{$reverser->id}/permissions", ['permissions' => ['payments.reverse']])
        ->assertOk();

    expect($viewer->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['payments.view'])
        ->and($reverser->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['payments.reverse']);
});

test('store admin cannot delegate received payment permissions without POS', function (): void {
    [, $admin, $employee] = paymentPermissionContext(false);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/store/users/{$employee->id}/permissions", ['permissions' => ['payments.view']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions');
});

test('driver does not gain administrative received payment access automatically', function (): void {
    [, $admin] = paymentPermissionContext(true);
    $driver = User::factory()->create(['store_id' => $admin->store_id]);
    $driver->assignRole('STORE_DRIVER');

    $this->actingAs($driver, 'sanctum')
        ->getJson('/api/v1/store/received-payments')
        ->assertForbidden();
});
