<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stop_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_stop_id');
            $table->uuid('product_id');
            $table->unsignedInteger('quantity_planned')->default(0);
            $table->unsignedInteger('quantity_loaded')->default(0);
            $table->unsignedInteger('quantity_delivered')->default(0);
            $table->timestamps();

            $table->foreign('route_stop_id')
                ->references('id')
                ->on('route_stops')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');

            $table->unique(['route_stop_id', 'product_id']);
            $table->index('route_stop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stop_items');
    }
};
