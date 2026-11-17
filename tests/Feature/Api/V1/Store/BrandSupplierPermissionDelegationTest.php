<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionFeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    PermissionFeatureResolver::clearCache();
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'STORE_USER', 'guard_name' => 'web']);
    foreach ([
        'store_users.edit',
        'brands.view', 'brands.create', 'brands.edit', 'brands.delete',
        'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
    ] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $features = collect(['multi_user', 'inventory', 'purchases'])->mapWithKeys(
        fn (string $code) => [$code => Feature::factory()->create(['code' => $code])]
    );
    $plan = Plan::factory()->create();
    $plan->features()->attach($features->pluck('id'));
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->admin = User::factory()->create(['store_id' => $this->store->id]);
    $this->admin->assignRole('STORE_ADMIN');
    $this->admin->givePermissionTo('store_users.edit');
    $this->employee = User::factory()->create(['store_id' => $this->store->id]);
    $this->employee->assignRole('STORE_USER');
});

test('store admins can delegate brands and suppliers only through their enabled features', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson(
        "/api/v1/store/users/{$this->employee->id}/permissions",
        ['permissions' => ['brands.view', 'brands.create', 'suppliers.view', 'suppliers.edit']]
    )->assertOk();

    expect($this->employee->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['brands.create', 'brands.view', 'suppliers.edit', 'suppliers.view']);
});

test('store admins cannot delegate suppliers when purchases is absent from the plan', function () {
    $plan = Plan::factory()->create();
    $plan->features()->attach(Feature::query()->whereIn('code', ['multi_user', 'inventory'])->pluck('id'));
    $this->store->update(['plan_id' => $plan->id]);

    $this->actingAs($this->admin->fresh(), 'sanctum')->postJson(
        "/api/v1/store/users/{$this->employee->id}/permissions",
        ['permissions' => ['suppliers.view']]
    )->assertUnprocessable()->assertJsonValidationErrors('permissions');
});
