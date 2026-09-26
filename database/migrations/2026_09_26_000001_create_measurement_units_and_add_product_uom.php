<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('symbol', 20);
            $table->string('category', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
        });

        $now = now();

        foreach ([
            ['code' => 'unit', 'name' => 'Unidad', 'symbol' => 'u', 'category' => 'unit'],
            ['code' => 'l', 'name' => 'Litro', 'symbol' => 'L', 'category' => 'volume'],
            ['code' => 'kg', 'name' => 'Kilogramo', 'symbol' => 'kg', 'category' => 'weight'],
            ['code' => 'm', 'name' => 'Metro', 'symbol' => 'm', 'category' => 'length'],
        ] as $unit) {
            DB::table('measurement_units')->insert([
                'id' => (string) Str::uuid(),
                ...$unit,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('stock_measurement_unit_id')->nullable()->after('stock_min');
            $table->decimal('sale_quantity_step', 18, 4)->default('1.0000')->after('stock_measurement_unit_id');
        });

        $unitId = DB::table('measurement_units')->where('code', 'unit')->value('id');

        if (! $unitId) {
            throw new RuntimeException('No se pudo crear la unidad de medida predeterminada.');
        }

        DB::table('products')->whereNull('stock_measurement_unit_id')->update([
            'stock_measurement_unit_id' => $unitId,
            'sale_quantity_step' => '1.0000',
        ]);

        if (DB::table('products')->whereNull('stock_measurement_unit_id')->exists()) {
            throw new RuntimeException('No se pudo asignar una unidad de medida a todos los productos existentes.');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('stock_measurement_unit_id')->nullable(false)->change();
            $table->foreign('stock_measurement_unit_id')
                ->references('id')
                ->on('measurement_units')
                ->restrictOnDelete();
            $table->index('stock_measurement_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['stock_measurement_unit_id']);
            $table->dropIndex(['stock_measurement_unit_id']);
            $table->dropColumn(['stock_measurement_unit_id', 'sale_quantity_step']);
        });

        Schema::dropIfExists('measurement_units');
    }
};
