<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QUANTITY_COLUMNS = [
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
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 18, 4)->default(0)->change();
            $table->decimal('stock_reserved', 18, 4)->default(0)->change();
            $table->decimal('stock_min', 18, 4)->default(0)->change();
        });

        Schema::table('operation_items', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->change();
            $table->decimal('previous_stock', 18, 4)->change();
            $table->decimal('current_stock', 18, 4)->change();
        });

        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->decimal('quantity_planned', 18, 4)->unsigned()->default(0)->change();
            $table->decimal('quantity_loaded', 18, 4)->unsigned()->default(0)->change();
            $table->decimal('quantity_delivered', 18, 4)->unsigned()->default(0)->change();
            $table->decimal('quantity_released_for_extra_sale', 18, 4)->unsigned()->default(0)->change();
        });

        Schema::table('delivery_discrepancies', function (Blueprint $table) {
            $table->decimal('quantity_loaded', 18, 4)->unsigned()->change();
            $table->decimal('quantity_delivered', 18, 4)->unsigned()->change();
            $table->decimal('difference_quantity', 18, 4)->unsigned()->change();
        });

        Schema::table('route_load_adjustments', function (Blueprint $table) {
            $table->decimal('old_quantity', 18, 4)->unsigned()->change();
            $table->decimal('new_quantity', 18, 4)->unsigned()->change();
        });

        Schema::table('extra_sale_allocations', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->unsigned()->change();
        });
    }

    public function down(): void
    {
        $this->ensureAllQuantitiesAreIntegral();

        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
            $table->integer('stock_reserved')->default(0)->change();
            $table->integer('stock_min')->default(0)->change();
        });

        Schema::table('operation_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('quantity')->change();
            $table->integer('previous_stock')->change();
            $table->integer('current_stock')->change();
        });

        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_planned')->default(0)->change();
            $table->unsignedInteger('quantity_loaded')->default(0)->change();
            $table->unsignedInteger('quantity_delivered')->default(0)->change();
            $table->unsignedInteger('quantity_released_for_extra_sale')->default(0)->change();
        });

        Schema::table('delivery_discrepancies', function (Blueprint $table) {
            $table->unsignedInteger('quantity_loaded')->change();
            $table->unsignedInteger('quantity_delivered')->change();
            $table->unsignedInteger('difference_quantity')->change();
        });

        Schema::table('route_load_adjustments', function (Blueprint $table) {
            $table->unsignedInteger('old_quantity')->change();
            $table->unsignedInteger('new_quantity')->change();
        });

        Schema::table('extra_sale_allocations', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
        });
    }

    private function ensureAllQuantitiesAreIntegral(): void
    {
        foreach (self::QUANTITY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                foreach (DB::table($table)->select($column)->cursor() as $row) {
                    $value = (string) $row->{$column};

                    if (bccomp($value, bcadd($value, '0', 0), 4) !== 0) {
                        throw new \RuntimeException('No se puede revertir la migración de cantidades porque existen valores fraccionarios.');
                    }
                }
            }
        }
    }
};
