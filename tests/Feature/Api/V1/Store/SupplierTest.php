<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete']);
});

test('creates scoped suppliers and permits multiple null tax ids', function () {
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/suppliers', ['business_name' => '  Química Cuyo  ', 'tax_id' => ''])->assertCreated()->assertJsonPath('data.business_name', 'Química Cuyo')->assertJsonPath('data.tax_id', null);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/suppliers', ['business_name' => 'Otro proveedor'])->assertCreated();
});

test('rejects same tax id in store and filters active suppliers', function () {
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/suppliers', ['business_name' => 'Activo', 'tax_id' => '30-1'])->assertCreated();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/suppliers', ['business_name' => 'Duplicado', 'tax_id' => '30-1'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/suppliers?is_active=1')->assertOk()->assertJsonCount(1, 'data.items');
});

test('isolates suppliers between stores and permits the same tax id elsewhere', function () {
    $otherPlan = Plan::factory()->create();
    $purchases = Feature::where('code', 'purchases')->firstOrFail();
    $otherPlan->features()->attach($purchases->id);
    $otherStore = Store::factory()->create(['plan_id' => $otherPlan->id]);
    $supplier = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Ajeno', 'tax_id' => '30-123']);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/suppliers')->assertOk()->assertJsonCount(0, 'data.items');
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$supplier->id}")->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/suppliers/{$supplier->id}", ['business_name' => 'Cambio'])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/suppliers/{$supplier->id}")->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/suppliers', ['business_name' => 'Propio', 'tax_id' => '30-123'])->assertCreated();
});

test('feature and individual supplier permissions guard endpoints', function () {
    $disabled = Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabled->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete']);
    $this->actingAs($disabledUser, 'sanctum')->getJson('/api/v1/store/suppliers')->assertForbidden();

    $supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Local']);
    foreach (['suppliers.view' => ['getJson', '/api/v1/store/suppliers'], 'suppliers.create' => ['postJson', '/api/v1/store/suppliers', ['business_name' => 'Nuevo']], 'suppliers.edit' => ['putJson', "/api/v1/store/suppliers/{$supplier->id}", ['business_name' => 'Editado']], 'suppliers.delete' => ['deleteJson', "/api/v1/store/suppliers/{$supplier->id}"]] as $permission => $call) {
        $user = User::factory()->create(['store_id' => $this->store->id]);
        $user->assignRole('STORE_ADMIN');
        $this->actingAs($user, 'sanctum')->{$call[0]}($call[1], $call[2] ?? [])->assertForbidden();
    }
});
