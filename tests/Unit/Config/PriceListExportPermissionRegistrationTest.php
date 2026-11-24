<?php

declare(strict_types=1);

test('registers price list export as a store permission in the POS feature', function () {
    $catalog = require __DIR__.'/../../../config/permissions_catalog.php';
    $registry = require __DIR__.'/../../../config/permission_registry.php';

    expect(collect($catalog['Listas de precios'])->pluck('name')->all())
        ->toContain('price_lists.export')
        ->and($registry['permissions']['price_lists.export'])
        ->toMatchArray([
            'scope' => 'store',
            'feature' => 'pos',
        ]);
});
