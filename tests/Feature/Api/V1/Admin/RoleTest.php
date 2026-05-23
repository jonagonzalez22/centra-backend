<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
  Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
  Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
});

function authHeaders(User $user): array
{
  $token = $user->createToken('test-token')->plainTextToken;

  return ['Authorization' => "Bearer $token"];
}

// ============================================================
// INDEX
// ============================================================

test('super admin can list all roles', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/roles');

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Listado de roles obtenido correctamente.')
    ->assertJsonCount(2, 'data.items')
    ->assertJsonPath('data.total', 2)
    ->assertJsonPath('data.per_page', 15)
    ->assertJsonPath('data.current_page', 1);
});

test('index includes users_count and permissions_count', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');
  $permission = Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  $role->givePermissionTo($permission);

  $user1 = User::factory()->create();
  $user1->assignRole('STORE_ADMIN');
  $user2 = User::factory()->create();
  $user2->assignRole('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/roles');

  $storeAdminData = collect($response->json('data.items'))
    ->firstWhere('name', 'STORE_ADMIN');

  expect($storeAdminData['users_count'])->toBe(2);
  expect($storeAdminData['permissions_count'])->toBe(1);
});

test('index includes permissions array', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');
  Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  Permission::create(['name' => 'stores.create', 'guard_name' => 'web']);
  $role->syncPermissions(['stores.view', 'stores.create']);

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/roles');

  $storeAdminData = collect($response->json('data.items'))
    ->firstWhere('name', 'STORE_ADMIN');

  expect($storeAdminData['permissions'])->toBeArray();
  expect($storeAdminData['permissions'])->toHaveCount(2);
  expect($storeAdminData['permissions'])->toContain('stores.view');
  expect($storeAdminData['permissions'])->toContain('stores.create');
});

test('index rejects unauthenticated request', function () {
  /** @var \Tests\TestCase $this */
  $response = $this->getJson('/api/v1/admin/roles');

  $response->assertStatus(401);
});

test('index rejects non super admin role', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->getJson('/api/v1/admin/roles');

  $response->assertStatus(403);
});

// ============================================================
// STORE
// ============================================================

test('super admin can create a new role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson('/api/v1/admin/roles', ['name' => 'editor']);

  $response->assertStatus(201)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Rol creado correctamente.')
    ->assertJsonPath('data.name', 'editor')
    ->assertJsonPath('data.guard_name', 'web');

  $this->assertDatabaseHas('roles', ['name' => 'editor', 'guard_name' => 'web']);
});

test('store rejects duplicate role name', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson('/api/v1/admin/roles', ['name' => 'SUPER_ADMIN']);

  $response->assertStatus(422)
    ->assertJsonPath('status', 'error')
    ->assertJsonPath('message', 'Error de validación.')
    ->assertJsonPath('errors.name.0', 'Ya existe un rol con este nombre.');
});

test('store requires name field', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson('/api/v1/admin/roles', []);

  $response->assertStatus(422)
    ->assertJsonPath('message', 'Error de validación.')
    ->assertJsonStructure(['errors' => ['name']]);
});

test('store rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->postJson('/api/v1/admin/roles', ['name' => 'editor']);

  $response->assertStatus(403);
});

// ============================================================
// SHOW
// ============================================================

test('super admin can view a role with permissions', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');
  Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  Permission::create(['name' => 'stores.create', 'guard_name' => 'web']);
  $role->syncPermissions(['stores.view', 'stores.create']);

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Rol obtenido correctamente.')
    ->assertJsonPath('data.id', $role->id)
    ->assertJsonPath('data.name', 'STORE_ADMIN')
    ->assertJsonCount(2, 'data.permissions')
    ->assertJsonPath('data.users_count', 0);
});

test('show returns 404 for non-existent role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/roles/9999');

  $response->assertStatus(404)
    ->assertJsonPath('message', 'Rol no encontrado.');
});

test('show rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $role = Role::findByName('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->getJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(403);
});

// ============================================================
// UPDATE
// ============================================================

test('super admin can update a role name', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  Role::create(['name' => 'editor', 'guard_name' => 'web']);
  $role = Role::findByName('editor');

  $response = $this->withHeaders(authHeaders($admin))
    ->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'moderator']);

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Rol actualizado correctamente.')
    ->assertJsonPath('data.name', 'moderator');

  $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'moderator']);
  $this->assertDatabaseMissing('roles', ['name' => 'editor']);
});

test('update rejects duplicate name', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  Role::create(['name' => 'editor', 'guard_name' => 'web']);
  $role = Role::findByName('editor');

  $response = $this->withHeaders(authHeaders($admin))
    ->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'SUPER_ADMIN']);

  $response->assertStatus(422)
    ->assertJsonPath('errors.name.0', 'Ya existe un rol con este nombre.');
});

test('update returns 404 for non-existent role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->putJson('/api/v1/admin/roles/9999', ['name' => 'test']);

  $response->assertStatus(404)
    ->assertJsonPath('message', 'Rol no encontrado.');
});

test('update rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->putJson("/api/v1/admin/roles/{$role->id}", ['name' => 'renamed']);

  $response->assertStatus(403);
});

// ============================================================
// DESTROY
// ============================================================

test('super admin can delete a role without users', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  Role::create(['name' => 'temp_role', 'guard_name' => 'web']);
  $role = Role::findByName('temp_role');

  $response = $this->withHeaders(authHeaders($admin))
    ->deleteJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Rol eliminado correctamente.');

  $this->assertDatabaseMissing('roles', ['name' => 'temp_role']);
});

test('destroy rejects deleting SUPER_ADMIN role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->deleteJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(422)
    ->assertJsonPath('message', 'No se puede eliminar el rol SUPER_ADMIN.');

  $this->assertDatabaseHas('roles', ['name' => 'SUPER_ADMIN']);
});

test('destroy rejects deleting role with assigned users', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->deleteJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(409)
    ->assertJsonPath('message', 'No se puede eliminar el rol porque tiene usuarios asignados.');

  $this->assertDatabaseHas('roles', ['name' => 'STORE_ADMIN']);
});

test('destroy returns 404 for non-existent role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->deleteJson('/api/v1/admin/roles/9999');

  $response->assertStatus(404)
    ->assertJsonPath('message', 'Rol no encontrado.');
});

test('destroy rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->deleteJson("/api/v1/admin/roles/{$role->id}");

  $response->assertStatus(403);
});

// ============================================================
// SYNC PERMISSIONS
// ============================================================

test('super admin can sync permissions to a role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", [
      'permissions' => ['stores.view', 'stores.create'],
    ]);

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Permisos sincronizados correctamente.')
    ->assertJsonCount(2, 'data.permissions')
    ->assertJsonPath('data.permissions', ['stores.view', 'stores.create']);

  expect($role->fresh()->permissions->pluck('name')->toArray())->toBe(['stores.view', 'stores.create']);
});

test('sync permissions replaces existing permissions', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');
  Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  Permission::create(['name' => 'stores.delete', 'guard_name' => 'web']);
  $role->syncPermissions(['stores.view', 'stores.delete']);

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", [
      'permissions' => ['stores.create', 'stores.edit'],
    ]);

  $response->assertStatus(200);

  $permissions = $role->fresh()->permissions->pluck('name')->toArray();
  expect($permissions)->toBe(['stores.create', 'stores.edit']);
  expect($permissions)->not->toContain('stores.view');
  expect($permissions)->not->toContain('stores.delete');
});

test('sync permissions creates missing permissions automatically', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", [
      'permissions' => ['brand.new.permission'],
    ]);

  $response->assertStatus(200);

  $this->assertDatabaseHas('permissions', ['name' => 'brand.new.permission', 'guard_name' => 'web']);
  expect($role->fresh()->permissions->pluck('name')->toArray())->toBe(['brand.new.permission']);
});

test('sync permissions rejects empty array', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');
  Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  $role->givePermissionTo('stores.view');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", [
      'permissions' => [],
    ]);

  $response->assertStatus(422)
    ->assertJsonPath('errors.permissions.0', 'El array de permisos es obligatorio.');
});

test('sync permissions returns 404 for non-existent role', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson('/api/v1/admin/roles/9999/sync-permissions', [
      'permissions' => ['stores.view'],
    ]);

  $response->assertStatus(404)
    ->assertJsonPath('message', 'Rol no encontrado.');
});

test('sync permissions requires permissions field', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", []);

  $response->assertStatus(422)
    ->assertJsonPath('message', 'Error de validación.')
    ->assertJsonStructure(['errors' => ['permissions']]);
});

test('sync permissions rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $role = Role::findByName('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->postJson("/api/v1/admin/roles/{$role->id}/sync-permissions", [
      'permissions' => ['stores.view'],
    ]);

  $response->assertStatus(403);
});

// ============================================================
// PERMISSIONS INDEX
// ============================================================

test('super admin can list all permissions grouped by resource', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  Permission::create(['name' => 'stores.view', 'guard_name' => 'web']);
  Permission::create(['name' => 'stores.create', 'guard_name' => 'web']);
  Permission::create(['name' => 'users.view', 'guard_name' => 'web']);

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/permissions');

  $response->assertStatus(200)
    ->assertJsonPath('status', 'success')
    ->assertJsonPath('message', 'Listado de permisos obtenido correctamente.')
    ->assertJsonPath('data.stores', ['stores.view', 'stores.create'])
    ->assertJsonPath('data.users', ['users.view']);
});

test('permissions index returns empty object when no permissions exist', function () {
  /** @var \Tests\TestCase $this */
  $admin = User::factory()->create();
  $admin->assignRole('SUPER_ADMIN');

  $response = $this->withHeaders(authHeaders($admin))
    ->getJson('/api/v1/admin/permissions');

  $response->assertStatus(200)
    ->assertJsonPath('data', []);
});

test('permissions index rejects unauthenticated request', function () {
  /** @var \Tests\TestCase $this */
  $response = $this->getJson('/api/v1/admin/permissions');

  $response->assertStatus(401);
});

test('permissions index rejects non super admin', function () {
  /** @var \Tests\TestCase $this */
  $user = User::factory()->create();
  $user->assignRole('STORE_ADMIN');

  $response = $this->withHeaders(authHeaders($user))
    ->getJson('/api/v1/admin/permissions');

  $response->assertStatus(403);
});
