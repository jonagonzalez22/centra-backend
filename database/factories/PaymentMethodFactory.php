<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Efectivo', 'Tarjeta de Crédito', 'Tarjeta de Débito', 'Transferencia', 'Mercado Pago']),
            'code' => strtolower($this->faker->unique()->word()),
            'icon' => $this->faker->optional()->word() . '.png',
            'is_active' => true,
        ];
    }
}
