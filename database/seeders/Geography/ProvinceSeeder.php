<?php

namespace Database\Seeders\Geography;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceSeeder extends Seeder
{
    private array $provinces = [
        ['name' => 'Buenos Aires', 'iso_code' => 'AR-B'],
        ['name' => 'Ciudad Autónoma de Buenos Aires', 'iso_code' => 'AR-C'],
        ['name' => 'Mendoza', 'iso_code' => 'AR-M'],
        ['name' => 'Córdoba', 'iso_code' => 'AR-X'],
        ['name' => 'Santa Fe', 'iso_code' => 'AR-S'],
        ['name' => 'Tucumán', 'iso_code' => 'AR-T'],
        ['name' => 'Entre Ríos', 'iso_code' => 'AR-E'],
        ['name' => 'Salta', 'iso_code' => 'AR-A'],
        ['name' => 'Chaco', 'iso_code' => 'AR-H'],
        ['name' => 'Corrientes', 'iso_code' => 'AR-W'],
        ['name' => 'Misiones', 'iso_code' => 'AR-N'],
        ['name' => 'Santiago del Estero', 'iso_code' => 'AR-G'],
        ['name' => 'San Juan', 'iso_code' => 'AR-J'],
        ['name' => 'Río Negro', 'iso_code' => 'AR-R'],
        ['name' => 'Neuquén', 'iso_code' => 'AR-Q'],
        ['name' => 'Formosa', 'iso_code' => 'AR-P'],
        ['name' => 'Chubut', 'iso_code' => 'AR-U'],
        ['name' => 'Santa Cruz', 'iso_code' => 'AR-Z'],
        ['name' => 'La Pampa', 'iso_code' => 'AR-L'],
        ['name' => 'San Luis', 'iso_code' => 'AR-D'],
        ['name' => 'Catamarca', 'iso_code' => 'AR-K'],
        ['name' => 'La Rioja', 'iso_code' => 'AR-F'],
        ['name' => 'Jujuy', 'iso_code' => 'AR-Y'],
        ['name' => 'Tierra del Fuego', 'iso_code' => 'AR-V'],
    ];

    private array $normalizedNames = [
        'Ciudad Autonoma De Bs As' => 'Ciudad Autónoma de Buenos Aires',
        'Santiago Del Estero' => 'Santiago del Estero',
        'Rio Negro' => 'Río Negro',
        'Neuquen' => 'Neuquén',
        'Tierra Del Fuego' => 'Tierra del Fuego',
    ];

    public function run(): void
    {
        foreach ($this->provinces as $province) {
            Province::updateOrCreate(
                ['iso_code' => $province['iso_code']],
                ['name' => $province['name']]
            );
        }
    }

    public function normalizeProvinceName(string $name): string
    {
        return $this->normalizedNames[$name] ?? $name;
    }
}
