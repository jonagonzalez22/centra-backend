<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->uuid('price_list_id')->nullable()->after('product_id');
            $table->string('price_list_name')->nullable()->after('price_list_id');
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('price_list_name');
            $table->foreign('price_list_id')->references('id')->on('price_lists')->restrictOnDelete();
            $table->index(['operation_id', 'product_id', 'price_list_id'], 'operation_items_commercial_identity_index');
        });
    }

    public function down(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropIndex('operation_items_commercial_identity_index');
            $table->dropForeign(['price_list_id']);
            $table->dropColumn(['price_list_id', 'price_list_name', 'discount_percentage']);
        });
    }
};
