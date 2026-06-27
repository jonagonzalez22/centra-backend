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

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Store::clearFeatureCache();
    PermissionFeatureResolver::clearCache();

    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);

    Permission::create(['name' => 'store_users.edit', 'guard_name' => 'web']);
    Permission::create(['name' => 'store_users.view', 'guard_name' => 'web']);
    Permission::create(['name' => 'products.view', 'guard_name' => 'web']);
    Permission::create(['name' => 'products.create', 'guard_name' => 'web']);
    Permission::create(['name' => 'products.edit', 'guard_name' => 'web']);
    Permission::create(['name' => 'products.delete', 'guard_name' => 'web']);
    Permission::create(['name' => 'categories.view', 'guard_name' => 'web']);
    Permission::create(['name' => 'categories.create', 'guard_name' => 'web']);
    Permission::create(['name' => 'categories.edit', 'guard_name' => 'web']);
    Permission::create(['name' => 'categories.delete', 'guard_name' => 'web']);
});

// ============================================================
// INDEX
// ============================================================

test('super admin can list all users', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    User::factory()->count(3)->create();

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users');

    $response->assertStatus(200)
        ->assertJsonCount(4, 'data.items') // 3 + admin
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.per_page', 15)
        ->assertJsonPath('data.current_page', 1);
});

test('store admin only sees users from their store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    User::factory()->count(2)->create(['store_id' => $store->id]);
    User::factory()->count(3)->create(); // other store

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/users');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items') // 2 + admin
        ->assertJsonPath('data.total', 3);
});

test('index filters users by name', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    User::factory()->create(['name' => 'Juan Perez']);
    User::factory()->create(['name' => 'Maria Lopez']);
    User::factory()->create(['name' => 'Juan Garcia']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users?name=Juan');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2);
});

test('index filters users by role', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user1 = User::factory()->create();
    $user1->assignRole('STORE_ADMIN');

    $user2 = User::factory()->create();
    $user2->assignRole('STORE_ADMIN');

    User::factory()->create(); // no role

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users?role=STORE_ADMIN');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2);
});

test('super admin can filter by store_id', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $store = Store::factory()->create();
    User::factory()->count(2)->create(['store_id' => $store->id]);
    User::factory()->count(3)->create(); // other store

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/admin/users?store_id={$store->id}");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.total', 2);
});

test('super admin can filter by is_active', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    User::factory()->count(2)->create(['is_active' => true]);
    User::factory()->count(3)->create(['is_active' => false]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users?is_active=true');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.total', 3);

    $response2 = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users?is_active=false');

    $response2->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.total', 3);
});

test('index rejects unauthenticated request', function () {
    /** @var \Tests\TestCase $this */
    $response = $this->getJson('/api/v1/admin/users');

    $response->assertStatus(401);
});

// ============================================================
// STORE
// ============================================================

test('super admin can create user in any store', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $store = Store::factory()->create();

    $data = [
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@centra.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'role' => 'STORE_ADMIN',
        'store_id' => $store->id,
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/users', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Nuevo Usuario')
        ->assertJsonPath('data.email', 'nuevo@centra.com');

    $this->assertDatabaseHas('users', ['email' => 'nuevo@centra.com', 'store_id' => $store->id]);
});

test('store admin creates user in their own store ignoring store_id sent', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 5]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $otherStore = Store::factory()->create();

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@centra.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'role' => 'STORE_USER',
        'store_id' => $otherStore->id, // should be ignored
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/store/users', $data);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'email' => 'nuevo@centra.com',
        'store_id' => $store->id, // assigned to admin's store, not otherStore
    ]);
});

test('store admin cannot assign super admin role', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'Nuevo Admin',
        'email' => 'admin@centra.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'role' => 'SUPER_ADMIN',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/store/users', $data);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para asignar ese rol.');
});

test('store fails validation with invalid data', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/users', []);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Error de validación.')
        ->assertJsonStructure(['errors' => ['name', 'email', 'password', 'role']]);
});

test('store rejects request without role', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@centra.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'role' => 'STORE_ADMIN',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/users', $data);

    $response->assertStatus(403);
});

test('store generates uuid automatically for new user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $data = [
        'name' => 'UUID User',
        'email' => 'uuid@centra.com',
        'password' => 'Password1',
        'password_confirmation' => 'Password1',
        'role' => 'STORE_ADMIN',
    ];

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson('/api/v1/admin/users', $data);

    $response->assertStatus(201);

    $user = User::where('email', 'uuid@centra.com')->first();
    expect($user)->not->toBeNull();
    expect(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $user->id))->toBe(1);
    expect($user->store_id)->toBeNull();
});

// ============================================================
// SHOW
// ============================================================

test('super admin can view any user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/admin/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

test('show store admin can view user from their store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/store/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});

test('show returns 404 for non-existent user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/admin/users/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404)
        ->assertJsonPath('message', 'Usuario no encontrado.');
});

test('store admin cannot view users from another store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $otherStore = Store::factory()->create();

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/store/users/{$otherUser->id}");

    $response->assertStatus(404)
        ->assertJsonPath('message', 'Usuario no encontrado.');
});

// ============================================================
// UPDATE
// ============================================================

test('super admin can update any user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['name' => 'Old Name']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/users/{$user->id}", ['name' => 'New Name']);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'New Name');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
});

test('store admin can update users from their store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id, 'name' => 'Old Name']);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/store/users/{$user->id}", ['name' => 'Updated']);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated');
});

test('store admin cannot assign super admin role on update', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/store/users/{$user->id}", ['role' => 'SUPER_ADMIN']);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No tenés permisos para asignar ese rol.');
});

test('super admin can change store_id on update', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $store1 = Store::factory()->create();
    $store2 = Store::factory()->create();

    $user = User::factory()->create(['store_id' => $store1->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/users/{$user->id}", ['store_id' => $store2->id]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'store_id' => $store2->id]);
});

test('update does partial update correctly', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@centra.com',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/users/{$user->id}", ['name' => 'Changed Name']);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Changed Name')
        ->assertJsonPath('data.email', 'original@centra.com'); // unchanged
});

test('super admin can deactivate a user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/users/{$user->id}", ['is_active' => false]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
});

test('super admin can reactivate a user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['is_active' => false]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/admin/users/{$user->id}", ['is_active' => true]);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
});

test('store admin can change is_active', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id, 'is_active' => true]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson("/api/v1/store/users/{$user->id}", ['is_active' => false, 'name' => 'Updated']);

    $response->assertStatus(200)
        ->assertJsonPath('data.is_active', false);

    $user->refresh();
    expect($user->is_active)->toBe(false);
    expect($user->name)->toBe('Updated');
});

// ============================================================
// DESTROY
// ============================================================

test('user cannot delete themselves', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/users/{$admin->id}");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No podés eliminar tu propio usuario.');

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('super admin can delete a user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/admin/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Usuario eliminado correctamente.');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('store admin can only delete users from their store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson("/api/v1/store/users/{$user->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('destroy returns 404 for non-existent user', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->create();
    $admin->assignRole('SUPER_ADMIN');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->deleteJson('/api/v1/admin/users/00000000-0000-0000-0000-000000000000');

    $response->assertStatus(404);
});

// ============================================================
// STORE USER PERMISSIONS
// ============================================================

test('store admin can view user direct permissions', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);
    $user->givePermissionTo('products.view');
    $user->givePermissionTo('products.create');

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/store/users/{$user->id}/permissions");

    $response->assertStatus(200);

    $permissions = $response->json('data.permissions');
    expect($permissions)->toContain('products.view')
        ->toContain('products.create');
});

test('store admin cannot view permissions of user from another store', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);
    $otherStore = Store::factory()->create();

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson("/api/v1/store/users/{$otherUser->id}/permissions");

    $response->assertStatus(404)
        ->assertJsonPath('message', 'Usuario no encontrado.');
});

test('store admin can sync user permissions', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);
    $user->givePermissionTo('products.view');

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson("/api/v1/store/users/{$user->id}/permissions", [
            'permissions' => ['products.create', 'products.view'],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Permisos sincronizados correctamente.');

    $user->refresh();
    expect($user->getDirectPermissions()->pluck('name')->toArray())->toBe(['products.create', 'products.view']);
});

test('store admin cannot assign admin/backoffice permissions', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $feature = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($feature->id, ['limit_value' => 10]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson("/api/v1/store/users/{$user->id}/permissions", [
            'permissions' => ['stores.create'],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Error de validación.');
});

test('store admin cannot assign permissions for feature not in plan', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Basic', 'price' => 49, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 5]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $user = User::factory()->create(['store_id' => $store->id]);

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson("/api/v1/store/users/{$user->id}/permissions", [
            'permissions' => ['products.view'],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Error de validación.');
});

test('store admin cannot modify own permissions', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson("/api/v1/store/users/{$admin->id}/permissions", [
            'permissions' => ['products.view'],
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No podés modificar tus propios permisos.');
});

test('store admin cannot modify super admin permissions', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.edit');
    $token = $admin->createToken('test-token')->plainTextToken;

    $superAdmin = User::factory()->create(['store_id' => $store->id]);
    $superAdmin->assignRole('SUPER_ADMIN');

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->postJson("/api/v1/store/users/{$superAdmin->id}/permissions", [
            'permissions' => ['products.view'],
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'No podés modificar los permisos de un SUPER_ADMIN.');
});

// ============================================================
// PERMISSION CATALOG
// ============================================================

test('store admin can see full permission catalog when store has all features', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $featureCategories = Feature::create(['code' => 'categories', 'name' => 'Categorías', 'description' => 'Gestión de categorías.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);
    $plan->features()->attach($featureCategories->id, ['limit_value' => 200]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.view');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/permissions/catalog');

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Catálogo de permisos obtenido correctamente.')
        ->assertJsonStructure([
            'data' => ['groups' => ['Inventario', 'Categorías', 'Usuarios']],
        ]);

    $groups = $response->json('data.groups');
    expect($groups['Inventario'])->toHaveCount(4)
        ->and($groups['Categorías'])->toHaveCount(4)
        ->and($groups['Usuarios'])->toHaveCount(4);
});

test('store admin sees only inventory group when store lacks multi_user feature', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Basic', 'price' => 49, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.view');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/permissions/catalog');

    $response->assertStatus(200);

    $groups = $response->json('data.groups');
    expect($groups)->toHaveKey('Inventario')
        ->not->toHaveKey('Categorías')
        ->not->toHaveKey('Usuarios');
});

test('store admin sees only users group when store lacks inventory and categories features', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Basic', 'price' => 49, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 5]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $admin = User::factory()->create(['store_id' => $store->id]);
    $admin->assignRole('STORE_ADMIN');
    $admin->givePermissionTo('store_users.view');
    $token = $admin->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/permissions/catalog');

    $response->assertStatus(200);

    $groups = $response->json('data.groups');
    expect($groups)->toHaveKey('Usuarios')
        ->not->toHaveKey('Inventario')
        ->not->toHaveKey('Categorías');
});

test('store user without store_users_view permission gets 403 on catalog', function () {
    /** @var \Tests\TestCase $this */
    $plan = Plan::create(['name' => 'Plan Pro', 'price' => 99, 'billing_cycle' => 'monthly', 'is_active' => true]);
    $featureMultiUser = Feature::create(['code' => 'multi_user', 'name' => 'Multi-Usuario', 'description' => 'Creación de múltiples cuentas.']);
    $featureInventory = Feature::create(['code' => 'inventory', 'name' => 'Inventario', 'description' => 'Gestión de inventario.']);
    $plan->features()->attach($featureMultiUser->id, ['limit_value' => 10]);
    $plan->features()->attach($featureInventory->id, ['limit_value' => 100]);

    $store = Store::factory()->create(['plan_id' => $plan->id]);

    $storeUser = User::factory()->create(['store_id' => $store->id]);
    $storeUser->assignRole('STORE_USER');
    $token = $storeUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/v1/store/permissions/catalog');

    $response->assertStatus(403);
});
