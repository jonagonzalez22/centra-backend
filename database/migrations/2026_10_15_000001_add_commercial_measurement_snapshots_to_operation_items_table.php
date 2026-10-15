<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->string('measurement_unit_code', 20)->nullable()->after('product_name');
            $table->string('measurement_unit_symbol', 20)->nullable()->after('measurement_unit_code');
            $table->decimal('sale_quantity_step', 18, 4)->nullable()->after('measurement_unit_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropColumn([
                'measurement_unit_code',
                'measurement_unit_symbol',
                'sale_quantity_step',
            ]);
        });
    }
};
