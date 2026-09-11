<?php

test('cash submit permission is registered in seed and catalog', function () {
    $seeder = file_get_contents(__DIR__.'/../../../database/seeders/PermissionSeeder.php');
    $catalog = require __DIR__.'/../../../config/permissions_catalog.php';
    $cashPermissions = collect($catalog['Caja'])->pluck('name');

    expect($seeder)->toContain("'cash.submit'")
        ->and($cashPermissions)->toContain('cash.view', 'cash.open', 'cash.submit', 'cash.close');
});
