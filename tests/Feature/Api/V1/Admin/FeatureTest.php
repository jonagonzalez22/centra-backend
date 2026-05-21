<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'BACKOFFICE_USER', 'guard_name' => 'web']);
});

test('api can list all features', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    Feature::create(['code' => 'pos', 'name' => 'Punto de venta', 'description' => 'Sistema POS']);
    Feature::create(['code' => 'multi_user', 'name' => 'Multi usuario', 'description' => 'Usuarios múltiples']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('api can filter features by code', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);
    Feature::create(['code' => 'multi_user', 'name' => 'Multi usuario']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features?code=pos');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.code', 'pos');
});

test('api can filter features by name', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);
    Feature::create(['code' => 'multi_user', 'name' => 'Multi usuario']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features?name=Punto');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Punto de venta');
});

test('api can filter features without plans', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $featureWithPlan = Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);
    Feature::create(['code' => 'multi_user', 'name' => 'Multi usuario']);

    $plan = Plan::create(['name' => 'Plan Básico', 'price' => 100]);
    $plan->features()->attach($featureWithPlan->id, ['limit_value' => 1]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features?has_plans=false');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.code', 'multi_user');
});

test('api can filter features with plans', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $featureWithPlan = Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);
    Feature::create(['code' => 'multi_user', 'name' => 'Multi usuario']);

    $plan = Plan::create(['name' => 'Plan Básico', 'price' => 100]);
    $plan->features()->attach($featureWithPlan->id, ['limit_value' => 1]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features?has_plans=true');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.code', 'pos');
});

test('api can create a new feature', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $data = [
        'code' => 'pos',
        'name' => 'Punto de venta',
        'description' => 'Sistema de punto de venta integrado',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/features', $data);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.code', 'pos')
        ->assertJsonPath('data.name', 'Punto de venta');

    $this->assertDatabaseHas('features', ['code' => 'pos', 'name' => 'Punto de venta']);
});

test('api cannot create feature with duplicate code', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);

    $data = [
        'code' => 'pos',
        'name' => 'Otro POS',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/features', $data);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('api cannot create feature with invalid code format', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $data = [
        'code' => 'Invalid-Code!',
        'name' => 'Test Feature',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/features', $data);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('api can show a feature', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $feature = Feature::create(['code' => 'pos', 'name' => 'Punto de venta', 'description' => 'Sistema POS']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/admin/features/{$feature->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.code', 'pos')
        ->assertJsonPath('data.name', 'Punto de venta');
});

test('api returns 404 for non-existent feature', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404)
        ->assertJsonPath('status', 'error');
});

test('api can update a feature', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $feature = Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);

    $data = [
        'code' => 'pos_updated',
        'name' => 'Punto de venta actualizado',
        'description' => 'Descripción actualizada',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/features/{$feature->id}", $data);

    $response->assertStatus(200)
        ->assertJsonPath('data.code', 'pos_updated')
        ->assertJsonPath('data.name', 'Punto de venta actualizado');

    $this->assertDatabaseHas('features', ['code' => 'pos_updated', 'name' => 'Punto de venta actualizado']);
});

test('api can delete a feature', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $feature = Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/features/{$feature->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseMissing('features', ['code' => 'pos']);
});

test('api cannot delete feature with associated plans', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');
    $token = $user->createToken('test-token')->plainTextToken;

    $feature = Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);
    $plan = Plan::create(['name' => 'Plan Básico', 'price' => 100]);
    $plan->features()->attach($feature->id, ['limit_value' => 1]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/features/{$feature->id}");

    $response->assertStatus(409)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'No se puede eliminar la funcionalidad porque tiene planes asociados.');

    $this->assertDatabaseHas('features', ['id' => $feature->id]);
});

test('api cannot access features without authentication', function () {
    /** @var \Tests\TestCase $this */
    $response = $this->getJson('/api/v1/admin/features');

    $response->assertStatus(401);
});

test('api cannot access features without authorized role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para realizar esta acción.');
});

test('api can access features with BACKOFFICE_USER role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $user->assignRole('BACKOFFICE_USER');
    $token = $user->createToken('test-token')->plainTextToken;

    Feature::create(['code' => 'pos', 'name' => 'Punto de venta']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/features');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});
