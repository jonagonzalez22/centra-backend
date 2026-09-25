<?php

use App\Models\Category;
use App\Models\DeliveryDiscrepancy;
use App\Models\ExtraSaleAllocation;
use App\Models\InventoryMovement;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteLoadAdjustment;
use App\Models\RouteStopItem;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('decimal quantity foundation', function () {
    it('uses decimal columns for every persisted inventory and logistics quantity', function () {
        foreach ([
            'products' => ['stock', 'stock_reserved', 'stock_min'],
            'operation_items' => ['quantity'],
            'inventory_movements' => ['quantity', 'previous_stock', 'current_stock'],
            'route_stop_items' => [
                'quantity_planned',
                'quantity_loaded',
                'quantity_delivered',
                'quantity_released_for_extra_sale',
            ],
            'delivery_discrepancies' => ['quantity_loaded', 'quantity_delivered', 'difference_quantity'],
            'route_load_adjustments' => ['old_quantity', 'new_quantity'],
            'extra_sale_allocations' => ['quantity'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                // SQLite reports DECIMAL affinity as numeric; MySQL reports decimal.
                expect(Schema::getColumnType($table, $column))->toBeIn(['decimal', 'numeric']);
            }
        }
    });

    it('casts existing integer values as normalized decimal strings', function () {
        $models = [
            new Product(['stock' => 10, 'stock_reserved' => 2, 'stock_min' => 1]),
            new OperationItem(['quantity' => 3]),
            new InventoryMovement(['quantity' => -2, 'previous_stock' => 10, 'current_stock' => 8]),
            new RouteStopItem([
                'quantity_planned' => 4,
                'quantity_loaded' => 3,
                'quantity_delivered' => 2,
                'quantity_released_for_extra_sale' => 1,
            ]),
            new DeliveryDiscrepancy(['quantity_loaded' => 4, 'quantity_delivered' => 2, 'difference_quantity' => 2]),
            new RouteLoadAdjustment(['old_quantity' => 4, 'new_quantity' => 3]),
            new ExtraSaleAllocation(['quantity' => 1]),
        ];

        expect($models[0]->stock)->toBe('10.0000')
            ->and($models[0]->stock_reserved)->toBe('2.0000')
            ->and($models[0]->stock_min)->toBe('1.0000')
            ->and($models[0]->available_stock)->toBe('8.0000')
            ->and($models[1]->quantity)->toBe('3.0000')
            ->and($models[2]->quantity)->toBe('-2.0000')
            ->and($models[2]->previous_stock)->toBe('10.0000')
            ->and($models[2]->current_stock)->toBe('8.0000')
            ->and($models[3]->quantity_planned)->toBe('4.0000')
            ->and($models[3]->quantity_loaded)->toBe('3.0000')
            ->and($models[3]->quantity_delivered)->toBe('2.0000')
            ->and($models[3]->quantity_released_for_extra_sale)->toBe('1.0000')
            ->and($models[4]->quantity_loaded)->toBe('4.0000')
            ->and($models[4]->quantity_delivered)->toBe('2.0000')
            ->and($models[4]->difference_quantity)->toBe('2.0000')
            ->and($models[5]->old_quantity)->toBe('4.0000')
            ->and($models[5]->new_quantity)->toBe('3.0000')
            ->and($models[6]->quantity)->toBe('1.0000');
    });

    it('preserves persisted whole quantities at the decimal scale', function () {
        $store = Store::factory()->create();
        $category = Category::factory()->belongsToStore($store)->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'stock' => 10,
            'stock_reserved' => 2,
            'stock_min' => 1,
        ]);

        expect($product->fresh()->stock)->toBe('10.0000')
            ->and($product->fresh()->stock_reserved)->toBe('2.0000')
            ->and($product->fresh()->stock_min)->toBe('1.0000');
    });

    it('allows rollback only while every stored quantity is integral', function () {
        $migration = require database_path('migrations/2026_09_25_000001_convert_inventory_quantities_to_decimal.php');

        try {
            $migration->down();

            expect(Schema::getColumnType('products', 'stock'))->toBe('integer');
        } finally {
            $migration->up();
        }

        expect(Schema::getColumnType('products', 'stock'))->toBeIn(['decimal', 'numeric']);
    });

    it('blocks rollback when at least one stored quantity is fractional', function () {
        $store = Store::factory()->create();
        $category = Category::factory()->belongsToStore($store)->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);

        DB::table('products')->where('id', $product->id)->update(['stock' => '1.2500']);
        $migration = require database_path('migrations/2026_09_25_000001_convert_inventory_quantities_to_decimal.php');

        expect(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'No se puede revertir la migración de cantidades porque existen valores fraccionarios.');
    });
});
