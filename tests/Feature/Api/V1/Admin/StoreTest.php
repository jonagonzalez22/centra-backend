<?php

use App\Models\Admin\BusinessType;
use App\Models\Admin\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
  Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
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
    ->assertJsonCount(2, 'data');
});

test('api can create a new store', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('SUPER_ADMIN');
  $token = $user->createToken('test-token')->plainTextToken;

  $businessType = BusinessType::create([
    'name' => 'Ferretería',
    'description' => 'Test',
    'status' => 'active',
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
    'status' => 'active',
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
    'status' => 'active',
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
    'status' => 'active',
  ];

  $response = $this->withHeader('Authorization', "Bearer $token")
    ->postJson('/api/v1/admin/stores', $data);

  $response->assertStatus(403)
    ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});
