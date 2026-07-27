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

      // Module: POS
      'pos.view',

      // Module: Inventory
      'inventory.view',
      'inventory.create',
      'inventory.edit',
      'inventory.adjust',
      'inventory.delete',

      // Module: Categories
      'categories.view',
      'categories.create',
      'categories.edit',
      'categories.delete',

      // Module: Commercial Groups
      'commercial_groups.view',
      'commercial_groups.create',
      'commercial_groups.edit',
      'commercial_groups.delete',

      // Module: Customers
      'customers.view',
      'customers.create',
      'customers.edit',
      'customers.delete',

      // Module: Customer Addresses
      'customer_addresses.view',
      'customer_addresses.create',
      'customer_addresses.edit',
      'customer_addresses.delete',

      // Module: Customer Contacts
      'customer_contacts.view',
      'customer_contacts.create',
      'customer_contacts.edit',
      'customer_contacts.delete',

      // Module: Store Payment Methods
      'store_payment_methods.view',
      'store_payment_methods.configure',
      
      // Module: Orders
      'orders.view',
      'orders.edit',

      // Module: Cash
      'cash.view',
      'cash.open',
      'cash.close',

      // Module: Vehicles
      'vehicles.view',
      'vehicles.create',
      'vehicles.edit',
      'vehicles.delete',

      // Module: Drivers
      'drivers.view',

      // Module: Logistics — Route Management
      'logistics.routes.view',
      'logistics.routes.manage',
      'logistics.routes.plan',
      'logistics.routes.revert',
      'logistics.routes.cancel',
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
