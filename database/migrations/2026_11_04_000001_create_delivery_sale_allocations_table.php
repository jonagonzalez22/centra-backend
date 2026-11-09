<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_sale_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('route_id');
            $table->uuid('route_stop_id');
            $table->uuid('route_stop_item_id');
            $table->uuid('operation_id');
            $table->uuid('operation_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity_delivered', 18, 4);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('net_amount', 12, 2);
            $table->uuid('price_list_id')->nullable();
            $table->string('price_list_name')->nullable();
            $table->decimal('discount_percentage', 12, 2)->nullable();
            $table->boolean('is_extra')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('route_id')->references('id')->on('delivery_routes')->restrictOnDelete();
            $table->foreign('route_stop_id')->references('id')->on('route_stops')->restrictOnDelete();
            $table->foreign('route_stop_item_id')->references('id')->on('route_stop_items')->restrictOnDelete();
            $table->foreign('operation_id')->references('id')->on('commercial_operations')->restrictOnDelete();
            $table->foreign('operation_item_id')->references('id')->on('operation_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('price_list_id')->references('id')->on('price_lists')->nullOnDelete();

            $table->unique(['route_stop_item_id', 'operation_item_id'], 'delivery_sale_allocations_stop_item_line_unique');
            $table->index(['store_id', 'occurred_at'], 'delivery_sale_allocations_store_occurred_index');
            $table->index('route_stop_id');
            $table->index('operation_id');
            $table->index('operation_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_sale_allocations');
    }
};
