<?php

namespace Database\Seeders;

use App\Models\DeliveryRejectionReason;
use Illuminate\Database\Seeder;

class DeliveryRejectionReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => 'customer_absent', 'label' => 'Cliente ausente', 'suggest_extra_sale' => true],
            ['code' => 'wrong_address', 'label' => 'Dirección incorrecta', 'suggest_extra_sale' => true],
            ['code' => 'rejected_by_customer', 'label' => 'Mercadería rechazada por el cliente', 'suggest_extra_sale' => true],
            ['code' => 'access_issue', 'label' => 'Problema de acceso al domicilio', 'suggest_extra_sale' => true],
            ['code' => 'damaged_goods', 'label' => 'Mercadería dañada', 'suggest_extra_sale' => false],
            ['code' => 'no_payment', 'label' => 'Cliente sin efectivo para abonar', 'suggest_extra_sale' => true],
        ];

        foreach ($reasons as $reason) {
            DeliveryRejectionReason::updateOrCreate(
                ['code' => $reason['code'], 'store_id' => null],
                [
                    'label' => $reason['label'],
                    'is_active' => true,
                    'suggest_extra_sale' => $reason['suggest_extra_sale'],
                ],
            );
        }
    }
}
