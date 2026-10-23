<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->unique('store_id');
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('purchase_id');
            $table->string('receipt_number', 20);
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('purchase_id')->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['store_id', 'receipt_number']);
            $table->index(['store_id', 'purchase_id']);
            $table->index(['store_id', 'received_at']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_receipt_id');
            $table->uuid('purchase_item_id');
            $table->uuid('product_id');
            $table->string('product_name_snapshot', 255);
            $table->string('product_sku_snapshot', 100);
            $table->string('measurement_unit_code_snapshot', 50);
            $table->string('measurement_unit_symbol_snapshot', 50);
            $table->decimal('quantity_received', 18, 4);
            $table->timestamps();

            $table->foreign('purchase_receipt_id')->references('id')->on('purchase_receipts')->restrictOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('purchase_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['purchase_receipt_id', 'purchase_item_id'], 'pri_receipt_item_unique');
            $table->index('product_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reference_type', 100)->nullable()->after('concept');
            $table->uuid('reference_id')->nullable()->after('reference_type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropColumn(['reference_type', 'reference_id']);
        });

        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_receipt_counters');
    }
};
