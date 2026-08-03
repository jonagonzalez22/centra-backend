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
      'commercial_groups.view',
      'commercial_groups.create',
      'commercial_groups.edit',
      'commercial_groups.delete',
      'customers.view',
      'customers.create',
      'customers.edit',
      'customers.delete',
      'customer_addresses.view',
      'customer_addresses.create',
      'customer_addresses.edit',
      'customer_addresses.delete',
      'customer_contacts.view',
      'customer_contacts.create',
      'customer_contacts.edit',
      'customer_contacts.delete',
      'vehicles.view',
      'vehicles.create',
      'vehicles.edit',
      'vehicles.delete',
      'drivers.view',
      'logistics.routes.view',
      'logistics.routes.manage',
      'logistics.routes.plan',
      'logistics.routes.revert',
      'logistics.routes.cancel',
      'logistics.routes.load',
      'logistics.routes.dispatch',
      'logistics.routes.reconcile',
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
