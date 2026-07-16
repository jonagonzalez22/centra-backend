<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('product_id');
            $table->string('product_name');
            $table->integer('quantity');
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('operation_id')
                ->references('id')
                ->on('commercial_operations')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');

            $table->index('operation_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_items');
    }
};
