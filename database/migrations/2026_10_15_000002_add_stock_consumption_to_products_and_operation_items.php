<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRODUCT_CONSUMPTION_CHECK = 'products_stock_consumption_configuration_check';

    private const PRODUCT_CONSUMPTION_SELF_CHECK = 'products_stock_consumption_not_self_check';

    private const PRODUCT_CONSUMPTION_STOCK_CHECK = 'products_stock_consumption_child_stock_check';

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('stock_source_product_id')->nullable()->after('stock_measurement_unit_id');
            $table->decimal('stock_consumption_quantity', 18, 4)->nullable()->after('stock_source_product_id');

            $table->foreign('stock_source_product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->index('stock_source_product_id');
        });

        Schema::table('operation_items', function (Blueprint $table) {
            $table->uuid('stock_source_product_id')->nullable()->after('product_id');
            $table->decimal('stock_consumption_quantity', 18, 4)->nullable()->after('stock_source_product_id');

            $table->index('stock_source_product_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::PRODUCT_CONSUMPTION_CHECK.' CHECK (
                    (stock_source_product_id IS NULL AND stock_consumption_quantity IS NULL)
                    OR (
                        stock_source_product_id IS NOT NULL
                        AND stock_consumption_quantity IS NOT NULL
                        AND stock_consumption_quantity > 0
                    )
                )');
            DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::PRODUCT_CONSUMPTION_SELF_CHECK.' CHECK (
                stock_source_product_id IS NULL OR stock_source_product_id <> id
            )');
            DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::PRODUCT_CONSUMPTION_STOCK_CHECK.' CHECK (
                stock_source_product_id IS NULL
                OR (stock = 0 AND stock_reserved = 0)
            )');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP CHECK '.self::PRODUCT_CONSUMPTION_CHECK);
            DB::statement('ALTER TABLE products DROP CHECK '.self::PRODUCT_CONSUMPTION_SELF_CHECK);
            DB::statement('ALTER TABLE products DROP CHECK '.self::PRODUCT_CONSUMPTION_STOCK_CHECK);
        }

        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropIndex(['stock_source_product_id']);
            $table->dropColumn(['stock_source_product_id', 'stock_consumption_quantity']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['stock_source_product_id']);
            $table->dropIndex(['stock_source_product_id']);
            $table->dropColumn(['stock_source_product_id', 'stock_consumption_quantity']);
        });
    }
};
