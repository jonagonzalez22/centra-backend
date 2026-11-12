<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            // POS sales are realized when their commercial line is created.
            // Order lines remain null: delivery_sale_allocations records each
            // separately realized delivery (including partial re-deliveries).
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable()->after('price');
        });

        Schema::table('delivery_sale_allocations', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable()->after('unit_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_sale_allocations', function (Blueprint $table) {
            $table->dropColumn('unit_cost_snapshot');
        });

        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost_snapshot');
        });
    }
};
