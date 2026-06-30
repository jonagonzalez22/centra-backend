<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'DNI', 'name' => 'DNI'],
            ['code' => 'CUIT', 'name' => 'CUIT'],
            ['code' => 'CUIL', 'name' => 'CUIL'],
            ['code' => 'PASSPORT', 'name' => 'Pasaporte'],
            ['code' => 'CI', 'name' => 'Cédula de Identidad'],
            ['code' => 'OTHER', 'name' => 'Otro'],
        ];

        foreach ($types as $type) {
            DocumentType::firstOrCreate(
                ['code' => $type['code']],
                ['name' => $type['name']]
            );
        }
    }
}
