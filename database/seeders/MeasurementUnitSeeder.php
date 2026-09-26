<?php

namespace Database\Seeders;

use App\Models\MeasurementUnit;
use Illuminate\Database\Seeder;

class MeasurementUnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'unit', 'name' => 'Unidad', 'symbol' => 'u', 'category' => 'unit'],
            ['code' => 'l', 'name' => 'Litro', 'symbol' => 'L', 'category' => 'volume'],
            ['code' => 'kg', 'name' => 'Kilogramo', 'symbol' => 'kg', 'category' => 'weight'],
            ['code' => 'm', 'name' => 'Metro', 'symbol' => 'm', 'category' => 'length'],
        ] as $unit) {
            MeasurementUnit::updateOrCreate(
                ['code' => $unit['code']],
                [...$unit, 'is_active' => true],
            );
        }
    }
}
