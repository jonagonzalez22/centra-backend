<?php

use App\Models\BusinessType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
});

test('api can list all business types', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    BusinessType::create(['name' => 'Supermercado', 'description' => 'Test', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/business-types');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('api can filter business types by name', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    BusinessType::create(['name' => 'Supermercado', 'description' => 'Test', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/business-types?name=Ferre');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Ferretería');
});

test('api can filter business types by status', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    BusinessType::create(['name' => 'Inactivo', 'description' => 'Test', 'status' => 'inactive']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/business-types?status=active');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Ferretería');
});

test('api can create a new business type', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'Ferretería',
        'description' => 'Businesses that sell hardware',
        'status' => 'active',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/business-types', $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.name', 'Ferretería')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('business_types', ['name' => 'Ferretería']);
});

test('api cannot create business type with duplicate name', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);

    $data = [
        'name' => 'Ferretería',
        'status' => 'active',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/business-types', $data);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('api can show a business type', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/admin/business-types/{$businessType->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Ferretería')
        ->assertJsonPath('data.status', 'active');
});

test('api returns 404 for non-existent business type', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/business-types/999');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('api can update a business type', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);

    $data = [
        'name' => 'Ferretería Actualizada',
        'status' => 'inactive',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/business-types/{$businessType->id}", $data);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Ferretería Actualizada')
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('business_types', ['name' => 'Ferretería Actualizada', 'status' => 'inactive']);
});

test('api can delete a business type', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/business-types/{$businessType->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('business_types', ['name' => 'Ferretería']);
});

test('api cannot delete business type with associated stores', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $businessType = BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);
    Store::factory()->create(['business_type_id' => $businessType->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/business-types/{$businessType->id}");

    $response->assertStatus(409)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'No se puede eliminar el tipo de negocio porque tiene tiendas asociadas.');

    $this->assertDatabaseHas('business_types', ['id' => $businessType->id]);
});

test('api cannot access business types without authentication', function () {
    /** @var \Tests\TestCase $this */
    $response = $this->getJson('/api/v1/admin/business-types');

    $response->assertStatus(401);
});

test('api cannot access business types without SUPER_ADMIN role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    BusinessType::create(['name' => 'Ferretería', 'description' => 'Test', 'status' => 'active']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/business-types');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});
