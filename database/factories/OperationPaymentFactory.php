<?php

namespace Database\Factories;

use App\Models\CommercialOperation;
use App\Models\OperationPayment;
use App\Models\StorePaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperationPaymentFactory extends Factory
{
    protected $model = OperationPayment::class;

    public function definition(): array
    {
        return [
            'operation_id' => CommercialOperation::factory(),
            'store_payment_method_id' => StorePaymentMethod::factory(),
            'amount' => $this->faker->randomFloat(2, 100, 1000),
            'reference' => $this->faker->optional()->numerify('######'),
            'payment_details' => null,
        ];
    }

    public function forOperation(CommercialOperation $operation): static
    {
        return $this->state(fn (array $attributes) => [
            'operation_id' => $operation->id,
        ]);
    }

    public function forStorePaymentMethod(StorePaymentMethod $storePaymentMethod): static
    {
        return $this->state(fn (array $attributes) => [
            'store_payment_method_id' => $storePaymentMethod->id,
        ]);
    }
}
