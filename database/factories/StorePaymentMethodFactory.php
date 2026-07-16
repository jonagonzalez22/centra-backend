<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class StorePaymentMethodFactory extends Factory
{
    protected $model = StorePaymentMethod::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'custom_name' => null,
            'is_enabled' => true,
            'requires_reference' => false,
            'account_details' => null,
            'sort_order' => 0,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function forPaymentMethod(PaymentMethod $paymentMethod): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method_id' => $paymentMethod->id,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }

    public function requiresReference(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_reference' => true,
        ]);
    }
}
