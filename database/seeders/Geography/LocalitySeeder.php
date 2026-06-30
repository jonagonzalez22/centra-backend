<?php

namespace Database\Seeders\Geography;

use App\Models\Locality;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class LocalitySeeder extends Seeder
{
    private const JSON_URL = 'https://raw.githubusercontent.com/wecodeio/ciudades-argentinas/master/ciudades-argentinas.json';

    private array $normalizedProvinceNames = [
        'Ciudad Autonoma De Bs As' => 'Ciudad Autónoma de Buenos Aires',
        'Santiago Del Estero' => 'Santiago del Estero',
        'Rio Negro' => 'Río Negro',
        'Neuquen' => 'Neuquén',
        'Tierra Del Fuego' => 'Tierra del Fuego',
    ];

    public function run(): void
    {
        $response = Http::get(self::JSON_URL);

        if ($response->failed()) {
            return;
        }

        $data = $response->json();

        $provinceMap = Province::pluck('id', 'name');

        foreach ($data as $provinceData) {
            $rawProvinceName = $provinceData['nombre'];
            $provinceName = $this->normalizeProvinceName($rawProvinceName);

            $provinceId = $provinceMap[$provinceName] ?? null;

            if (! $provinceId) {
                continue;
            }

            foreach ($provinceData['ciudades'] as $city) {
                Locality::updateOrCreate(
                    [
                        'province_id' => $provinceId,
                        'name' => $city['nombre'],
                    ],
                    [
                        'zip_code' => null,
                    ]
                );
            }
        }
    }

    private function normalizeProvinceName(string $name): string
    {
        return $this->normalizedProvinceNames[$name] ?? $name;
    }
}
