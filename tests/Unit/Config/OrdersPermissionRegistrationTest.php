<?php

declare(strict_types=1);

describe('Permissions Catalog', function () {
    test('contains Pedidos section with orders.edit permission', function () {
        $catalog = require __DIR__ . '/../../../config/permissions_catalog.php';

        expect($catalog)->toHaveKey('Pedidos');

        $pedidosPermissions = $catalog['Pedidos'];
        $names = collect($pedidosPermissions)->pluck('name')->toArray();

        expect($names)->toContain('orders.edit');
    });
});

describe('Permissions Mapping', function () {
    test('maps orders to pos feature', function () {
        $mapping = require __DIR__ . '/../../../config/permissions_mapping.php';

        expect($mapping)->toHaveKey('orders')
            ->and($mapping['orders'])->toBe('pos');
    });
});
