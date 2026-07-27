<?php

use App\Models\BusinessType;
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

    $role = Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);

    $permissions = ['stores.view', 'stores.create', 'stores.edit', 'stores.delete'];
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    $role->syncPermissions($permissions);
});

test('api can list all stores', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    Store::factory()->create(['name' => 'Store A']);
    Store::factory()->create(['name' => 'Store B']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/stores');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('api can create a new store', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Ferretería Central',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678906',
        'address' => 'Av. Corrientes 1234',
        'state' => 'Buenos Aires',
        'city' => 'Buenos Aires',
        'country' => 'Argentina',
        'phone' => '+541112345678',
        'email' => 'central@test.com',
        'is_active' => true,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(201);
    $this->assertDatabaseHas('stores', ['name' => 'Ferretería Central']);
});

test('api cannot list stores without SUPER_ADMIN role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    Store::factory()->create(['name' => 'Store A']);
    Store::factory()->create(['name' => 'Store B']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/stores');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});

test('api cannot create store without SUPER_ADMIN role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Ferretería Central',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678906',
        'address' => 'Av. Corrientes 1234',
        'state' => 'Buenos Aires',
        'city' => 'Buenos Aires',
        'country' => 'Argentina',
        'phone' => '+541112345678',
        'email' => 'central@test.com',
        'is_active' => true,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});

test('api can get store filter options', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $btActive = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    $btInactive = BusinessType::create(['name' => 'Inactivo', 'description' => 'Test', 'status' => 'inactive']);

    $planActive = Plan::create(['name' => 'Plan Básico', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $planInactive = Plan::create(['name' => 'Plan Desactivado', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => false]);

    Store::factory()->create(['business_type_id' => $btActive->id, 'plan_id' => $planActive->id, 'is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/stores/filter-options');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(1, 'data.business_types')
        ->assertJsonCount(2, 'data.plans')
        ->assertJsonCount(2, 'data.is_active')
        ->assertJsonPath('data.business_types.0.name', 'Ferretería')
        ->assertJsonPath('data.plans.0.name', 'Plan Básico');
});

test('filter options returns all master table values regardless of store data', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $bt = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    $plan = Plan::create(['name' => 'Plan Básico', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true]);

    Store::factory()->create(['business_type_id' => $bt->id, 'plan_id' => $plan->id, 'is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/stores/filter-options');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.business_types')
        ->assertJsonCount(1, 'data.plans')
        ->assertJsonCount(2, 'data.is_active')
        ->assertJsonPath('data.is_active.0.value', true)
        ->assertJsonPath('data.is_active.0.label', 'Activo')
        ->assertJsonPath('data.is_active.1.value', false)
        ->assertJsonPath('data.is_active.1.label', 'Inactivo');
});

test('api can create store with valid coordinates', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Tienda con GPS',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678906',
        'address' => 'Av. Rivadavia 1000',
        'state' => 'CABA',
        'city' => 'CABA',
        'country' => 'Argentina',
        'phone' => '+541112345670',
        'email' => 'gps@test.com',
        'is_active' => true,
        'latitude' => -34.603722,
        'longitude' => -58.381592,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(201);
    $this->assertDatabaseHas('stores', [
        'name' => 'Tienda con GPS',
        'latitude' => '-34.603722',
        'longitude' => '-58.381592',
    ]);
});

test('api can create store with null coordinates', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Tienda sin GPS',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678906',
        'address' => 'Av. Callao 500',
        'state' => 'CABA',
        'city' => 'CABA',
        'country' => 'Argentina',
        'phone' => '+541112345671',
        'email' => 'nocoords@test.com',
        'is_active' => true,
        'latitude' => null,
        'longitude' => null,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(201);
    $this->assertDatabaseHas('stores', ['name' => 'Tienda sin GPS', 'latitude' => null, 'longitude' => null]);
});

test('api rejects store with out-of-range latitude', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Tienda Lat Invalida',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678902',
        'address' => 'Av. Santa Fe 2000',
        'state' => 'CABA',
        'city' => 'CABA',
        'country' => 'Argentina',
        'phone' => '+541112345672',
        'email' => 'badlat@test.com',
        'is_active' => true,
        'latitude' => 200,
        'longitude' => -58.381592,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(422)
        ->assertJsonPath('errors.latitude.0', 'La latitud debe ser menor o igual a 90.');
});

test('api rejects store with out-of-range longitude', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create([
        'name' => 'Ferretería',
        'description' => 'Test',
        'is_active' => true,
    ]);

    $data = [
        'name' => 'Tienda Lon Invalida',
        'business_type_id' => $businessType->id,
        'cuit' => '20345678903',
        'address' => 'Av. Cabildo 300',
        'state' => 'CABA',
        'city' => 'CABA',
        'country' => 'Argentina',
        'phone' => '+541112345673',
        'email' => 'badlon@test.com',
        'is_active' => true,
        'latitude' => -34.603722,
        'longitude' => 300,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/stores', $data);

    $response->assertStatus(422)
        ->assertJsonPath('errors.longitude.0', 'La longitud debe ser menor o igual a 180.');
});

test('api cannot get filter options without SUPER_ADMIN role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/stores/filter-options');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});
