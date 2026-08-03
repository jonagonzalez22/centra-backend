<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_discrepancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_stop_item_id');
            $table->uuid('product_id');
            $table->unsignedInteger('quantity_loaded');
            $table->unsignedInteger('quantity_delivered');
            $table->unsignedInteger('difference_quantity'); // loaded - delivered
            $table->string('resolution_type')->nullable(); // returned, pending_redelivery, missing, damaged, rejected_by_customer, other
            $table->text('notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('route_stop_item_id')
                ->references('id')
                ->on('route_stop_items')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');

            $table->foreign('resolved_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->unique('route_stop_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_discrepancies');
    }
};
