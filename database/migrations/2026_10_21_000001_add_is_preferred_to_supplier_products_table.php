<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->boolean('is_preferred')->default(false)->after('supplier_product_name');
            $table->index(['store_id', 'product_id', 'is_preferred']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'product_id', 'is_preferred']);
            $table->dropColumn('is_preferred');
        });
    }
};
