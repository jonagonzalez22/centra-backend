<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SuperAdminPermissionsSeeder extends Seeder
{
  public function run(): void
  {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permissions = [
      'plans.view',
      'plans.create',
      'plans.edit',
      'plans.delete',
      'roles.view',
      'roles.create',
      'roles.edit',
      'roles.delete',
      'settings.view',
      'settings.edit',
      'stores.view',
      'stores.create',
      'stores.edit',
      'stores.delete',
      'users.view',
      'users.create',
      'users.edit',
      'users.delete',
    ];

    foreach ($permissions as $permission) {
      Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::findByName('SUPER_ADMIN', 'web');

    if (!$role) {
      throw new \Exception('El rol SUPER_ADMIN no existe. Ejecutá primero el seeder de roles.');
    }

    $role->syncPermissions(Permission::all());
  }
}
