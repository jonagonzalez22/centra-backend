<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_sale_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('route_id');
            $table->uuid('destination_stop_id');
            $table->uuid('destination_stop_item_id');
            $table->uuid('source_stop_item_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->foreign('route_id')->references('id')->on('delivery_routes')->onDelete('cascade');
            $table->foreign('destination_stop_id')->references('id')->on('route_stops')->onDelete('cascade');
            $table->foreign('destination_stop_item_id')->references('id')->on('route_stop_items')->onDelete('cascade');
            $table->foreign('source_stop_item_id')->references('id')->on('route_stop_items')->onDelete('cascade');

            $table->index(['route_id', 'source_stop_item_id']);
            $table->index(['destination_stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_sale_allocations');
    }
};
