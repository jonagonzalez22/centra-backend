<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuation_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('stock_source_product_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('known_quantity', 18, 4);
            $table->decimal('unknown_quantity', 18, 4);
            // Value and average refer to the known portion when status is mixed.
            $table->decimal('total_value', 20, 6)->default(0);
            $table->decimal('average_unit_cost', 20, 6)->nullable();
            $table->string('cost_status', 20);
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('stock_source_product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['store_id', 'stock_source_product_id'], 'inventory_valuation_balance_source_unique');
            $table->index(['store_id', 'cost_status']);
        });

        Schema::create('inventory_valuation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('stock_source_product_id');
            $table->string('type', 40);
            $table->string('reference_type', 100);
            $table->uuid('reference_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->string('cost_status', 20);
            $table->timestamp('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('stock_source_product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['reference_type', 'reference_id', 'stock_source_product_id', 'cost_status'],
                'inventory_valuation_entry_reference_unique'
            );
            $table->index(['store_id', 'stock_source_product_id', 'occurred_at'], 'inventory_valuation_entry_source_date_index');
            $table->index(['store_id', 'type', 'occurred_at'], 'inventory_valuation_entry_type_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_entries');
        Schema::dropIfExists('inventory_valuation_balances');
    }
};
