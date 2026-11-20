<?php

declare(strict_types=1);

describe('Permissions Catalog', function () {
    test('contains Pedidos section with orders.edit permission', function () {
        $catalog = require __DIR__.'/../../../config/permissions_catalog.php';

        expect($catalog)->toHaveKey('Pedidos');

        $pedidosPermissions = $catalog['Pedidos'];
        $names = collect($pedidosPermissions)->pluck('name')->toArray();

        expect($names)->toContain('orders.create', 'orders.edit', 'orders.cancel', 'orders.collect');
    });
});

describe('Permissions Mapping', function () {
    test('lets orders resolve to its own feature', function () {
        $mapping = require __DIR__.'/../../../config/permissions_mapping.php';

        expect($mapping)->not->toHaveKey('orders');
    });
});

test('purchases lifecycle permissions are registered in the catalog', function () {
    $catalog = require __DIR__.'/../../../config/permissions_catalog.php';

    expect($catalog)->toHaveKey('Compras')
        ->and(collect($catalog['Compras'])->pluck('name')->all())
        ->toContain('purchases.receive', 'purchases.close');
});
