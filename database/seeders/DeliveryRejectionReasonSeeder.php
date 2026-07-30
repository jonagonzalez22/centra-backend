<?php

namespace Database\Seeders;

use App\Models\DeliveryRejectionReason;
use Illuminate\Database\Seeder;

class DeliveryRejectionReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => 'cliente_ausente', 'label' => 'Cliente ausente'],
            ['code' => 'producto_danado', 'label' => 'Producto dañado'],
            ['code' => 'error_pedido', 'label' => 'Error en pedido / producto equivocado'],
            ['code' => 'local_cerrado', 'label' => 'Local cerrado'],
            ['code' => 'cliente_sin_espacio', 'label' => 'Cliente sin espacio'],
            ['code' => 'otros', 'label' => 'Otros'],
        ];

        foreach ($reasons as $reason) {
            DeliveryRejectionReason::firstOrCreate(
                ['code' => $reason['code'], 'store_id' => null],
                ['label' => $reason['label'], 'is_active' => true],
            );
        }
    }
}
