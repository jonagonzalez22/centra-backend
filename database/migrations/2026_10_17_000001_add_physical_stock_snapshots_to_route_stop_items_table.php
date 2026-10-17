<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOT_CHECK = 'route_stop_items_physical_snapshot_check';

    public function up(): void
    {
        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->dropUnique('route_stop_items_route_stop_id_product_id_unique');

            $table->uuid('stock_source_product_id')->nullable()->after('product_id');
            $table->decimal('stock_consumption_quantity', 18, 4)->nullable()->after('stock_source_product_id');
            $table->string('stock_source_measurement_unit_code', 20)->nullable()->after('stock_consumption_quantity');
            $table->string('stock_source_measurement_unit_symbol', 20)->nullable()->after('stock_source_measurement_unit_code');

            $table->foreign('stock_source_product_id', 'route_stop_items_stock_source_fk')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->index('stock_source_product_id', 'route_stop_items_stock_source_idx');
            $table->unique([
                'route_stop_id',
                'product_id',
                'stock_source_product_id',
                'stock_consumption_quantity',
            ], 'route_stop_items_physical_snapshot_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE route_stop_items ADD CONSTRAINT '.self::SNAPSHOT_CHECK.' CHECK (
                (stock_source_product_id IS NULL
                    AND stock_consumption_quantity IS NULL
                    AND stock_source_measurement_unit_code IS NULL
                    AND stock_source_measurement_unit_symbol IS NULL)
                OR (
                    stock_source_product_id IS NOT NULL
                    AND stock_consumption_quantity IS NOT NULL
                    AND stock_consumption_quantity > 0
                    AND stock_source_measurement_unit_code IS NOT NULL
                    AND stock_source_measurement_unit_symbol IS NOT NULL
                )
            )');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE route_stop_items DROP CHECK '.self::SNAPSHOT_CHECK);
        }

        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->dropUnique('route_stop_items_physical_snapshot_unique');
            $table->dropForeign('route_stop_items_stock_source_fk');
            $table->dropIndex('route_stop_items_stock_source_idx');
            $table->dropColumn([
                'stock_source_product_id',
                'stock_consumption_quantity',
                'stock_source_measurement_unit_code',
                'stock_source_measurement_unit_symbol',
            ]);
            $table->unique(['route_stop_id', 'product_id']);
        });
    }
};
