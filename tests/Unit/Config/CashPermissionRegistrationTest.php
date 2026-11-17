<?php

test('cash submit permission is registered in the canonical registry and catalog', function () {
    $registry = require __DIR__.'/../../../config/permission_registry.php';
    $catalog = require __DIR__.'/../../../config/permissions_catalog.php';
    $cashPermissions = collect($catalog['Caja'])->pluck('name');

    expect($registry['permissions'])->toHaveKey('cash.submit')
        ->and($cashPermissions)->toContain('cash.view', 'cash.open', 'cash.submit', 'cash.close');
});
