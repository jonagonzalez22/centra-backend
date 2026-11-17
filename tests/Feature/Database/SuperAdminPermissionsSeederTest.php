<?php

use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('assigns store admin the base payment method and price list permissions without removing existing permissions', function () {
    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    $storeAdmin = Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders.edit', 'guard_name' => 'web']);
    $storeAdmin->givePermissionTo('orders.edit');

    (new SuperAdminPermissionsSeeder)->run();
    (new SuperAdminPermissionsSeeder)->run();

    expect($storeAdmin->fresh()->hasAllPermissions([
        'store_payment_methods.view',
        'store_payment_methods.configure',
        'price_lists.view',
        'price_lists.create',
        'price_lists.edit',
        'price_lists.delete',
        'brands.view',
        'brands.create',
        'brands.edit',
        'brands.delete',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.delete',
        'orders.edit',
    ]))->toBeTrue();
});
