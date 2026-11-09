<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('delivery sale allocations migration rolls back and restores cleanly', function () {
    $migration = require database_path('migrations/2026_11_04_000001_create_delivery_sale_allocations_table.php');

    expect(Schema::hasTable('delivery_sale_allocations'))->toBeTrue();
    $migration->down();
    expect(Schema::hasTable('delivery_sale_allocations'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('delivery_sale_allocations'))->toBeTrue();
});
