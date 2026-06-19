<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('category_id');
            $table->string('name', 255);
            $table->string('sku', 100);
            $table->string('barcode')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->integer('stock_reserved')->default(0);
            $table->integer('stock_min')->default(0);
            $table->uuid('parent_product_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->onDelete('restrict');

            $table->foreign('parent_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('set null');

            $table->unique(['store_id', 'sku']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
