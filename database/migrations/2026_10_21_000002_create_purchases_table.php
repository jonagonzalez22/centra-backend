<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->unique('store_id');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('supplier_id');
            $table->string('purchase_number', 20);
            $table->string('purchase_type', 20);
            $table->string('status', 30)->default('draft');
            $table->string('supplier_business_name', 150);
            $table->string('supplier_tax_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['store_id', 'purchase_number']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'purchase_type']);
            $table->index(['store_id', 'supplier_id']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_id');
            $table->uuid('product_id');
            $table->string('product_name', 255);
            $table->string('product_sku', 100);
            $table->string('supplier_code', 100)->nullable();
            $table->string('supplier_product_name', 255)->nullable();
            $table->string('measurement_unit_code', 50);
            $table->string('measurement_unit_symbol', 50);
            $table->decimal('quantity_ordered', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->foreign('purchase_id')->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['purchase_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('purchase_counters');
    }
};
