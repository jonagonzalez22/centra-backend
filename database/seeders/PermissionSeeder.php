<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
  public function run(): void
  {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // 📦 Define all backoffice permissions grouped by module
    $permissions = [
      // Module: Stores
      'stores.view',
      'stores.create',
      'stores.edit',
      'stores.delete',

      // Module: Backoffice users
      'backoffice_users.view',
      'backoffice_users.create',
      'backoffice_users.edit',
      'backoffice_users.delete',

      // Module: Plans
      'plans.view',
      'plans.create',
      'plans.edit',
      'plans.delete',

      // Module: Geography
      'geography.view',

      // Module: Commercial Groups
      'commercial_groups.view',
      'commercial_groups.create',
      'commercial_groups.edit',
      'commercial_groups.delete',
    ];

    // Create each permission if it doesn't exist
    foreach ($permissions as $permission) {
      Permission::firstOrCreate(['name' => $permission]);
    }

    // SUPER_ADMIN → all permissions
    $superAdmin = Role::firstOrCreate(['name' => 'SUPER_ADMIN']);
    $superAdmin->syncPermissions(Permission::all());

    // BACKOFFICE_USER → read-only for now
    $backofficeUser = Role::firstOrCreate(['name' => 'BACKOFFICE_USER']);
    $backofficeUser->syncPermissions([
      'stores.view',
      'plans.view',
    ]);
  }
}
