<?php

return [
    'orders' => 'pos',
    'sales' => 'pos',
    'sales_history' => 'pos',
    'price_lists' => 'pos',
    'payments' => 'pos',
    // Categories are an Inventory capability. Their permissions remain
    // independent, but their commercial availability follows Inventory.
    'categories' => 'inventory',
    'brands' => 'inventory',
    'suppliers' => 'purchases',
    'store_users' => 'multi_user',
    'customer_addresses' => 'customers',
    'customer_contacts' => 'customers',
    'store_payment_methods' => 'store_settings',
    'vehicles' => 'deliveries',
    'drivers' => 'deliveries',
    'logistics' => 'deliveries',
];
