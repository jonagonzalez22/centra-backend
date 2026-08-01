<?php

namespace Database\Seeders;

use App\Models\DeliveryRejectionReason;
use Illuminate\Database\Seeder;

class DeliveryRejectionReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => 'customer_absent', 'label' => 'Cliente ausente'],
            ['code' => 'wrong_address', 'label' => 'Dirección incorrecta'],
            ['code' => 'rejected_by_customer', 'label' => 'Mercadería rechazada por el cliente'],
            ['code' => 'access_issue', 'label' => 'Problema de acceso al domicilio'],
            ['code' => 'damaged_goods', 'label' => 'Mercadería dañada'],
            ['code' => 'no_payment', 'label' => 'Cliente sin efectivo para abonar'],
        ];

        foreach ($reasons as $reason) {
            DeliveryRejectionReason::firstOrCreate(
                ['code' => $reason['code'], 'store_id' => null],
                ['label' => $reason['label'], 'is_active' => true],
            );
        }
    }
}
