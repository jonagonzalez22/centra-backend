<?php

/*
 * CENTRA's sole versioned permission authority.  Do not infer permissions from
 * routes: a route is an enforcement point, while this file is the reviewed
 * policy used to reconstruct a database.
 */
$platform = [
    'stores.view', 'stores.create', 'stores.edit', 'stores.delete',
    'plans.view', 'plans.create', 'plans.edit', 'plans.delete',
    'users.view', 'users.create', 'users.edit', 'users.delete',
    'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
    'settings.view', 'settings.edit',
    'payment_methods.view', 'payment_methods.create', 'payment_methods.edit', 'payment_methods.delete',
];

$store = [
    'pos' => ['pos.view', 'pos.create'],
    'reports' => ['reports.view'],
    'purchases' => ['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.receive', 'purchases.close', 'purchases.cancel', 'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete'],
    'inventory' => ['inventory.view', 'inventory.create', 'inventory.edit', 'inventory.adjust', 'inventory.delete', 'categories.view', 'categories.create', 'categories.edit', 'categories.delete', 'brands.view', 'brands.create', 'brands.edit', 'brands.delete'],
    'customers' => ['customers.view', 'customers.create', 'customers.edit', 'customers.delete', 'customer_addresses.view', 'customer_addresses.create', 'customer_addresses.edit', 'customer_addresses.delete', 'customer_contacts.view', 'customer_contacts.create', 'customer_contacts.edit', 'customer_contacts.delete'],
    'cash' => ['cash.view', 'cash.open', 'cash.submit', 'cash.close'],
    'financial_incidents' => ['financial_incidents.view', 'financial_incidents.manage'],
    'orders' => ['orders.view', 'orders.create', 'orders.edit', 'orders.cancel', 'orders.collect'],
    'pos_extra' => ['sales_history.view', 'sales_history.print', 'sales.cancel', 'price_lists.view', 'price_lists.create', 'price_lists.edit', 'price_lists.delete', 'price_lists.export', 'payments.view', 'payments.reverse'],
    'multi_user' => ['store_users.view', 'store_users.create', 'store_users.edit', 'store_users.delete'],
    'store_settings' => ['store_payment_methods.view', 'store_payment_methods.configure'],
    'deliveries' => ['vehicles.view', 'vehicles.create', 'vehicles.edit', 'vehicles.delete', 'drivers.view', 'logistics.routes.view', 'logistics.routes.manage', 'logistics.routes.plan', 'logistics.routes.revert', 'logistics.routes.cancel', 'logistics.routes.load', 'logistics.routes.dispatch', 'logistics.routes.reconcile'],
    'geography' => ['geography.view'],
];

$definitions = [];
foreach ($platform as $name) {
    $definitions[$name] = ['guard' => 'web', 'scope' => 'platform', 'feature' => null, 'delegable' => false, 'delegable_roles' => [], 'baseline_roles' => ['SUPER_ADMIN']];
}
foreach ($store as $feature => $names) {
    // This legacy group remains commercially part of POS; its permission
    // identifiers are retained for backwards compatibility.
    $resolvedFeature = $feature === 'pos_extra' ? 'pos' : $feature;
    foreach ($names as $name) {
        $isEmployeeAdministration = str_starts_with($name, 'store_users.');
        $definitions[$name] = ['guard' => 'web', 'scope' => 'store', 'feature' => $resolvedFeature, 'delegable' => ! $isEmployeeAdministration, 'delegable_roles' => $isEmployeeAdministration ? [] : ['STORE_USER'], 'baseline_roles' => ['STORE_ADMIN']];
    }
}

return [
    'roles' => ['SUPER_ADMIN', 'BACKOFFICE_USER', 'STORE_ADMIN', 'STORE_USER', 'STORE_DRIVER'],
    'backoffice_baseline' => ['stores.view', 'plans.view'],
    'permissions' => $definitions,
];
