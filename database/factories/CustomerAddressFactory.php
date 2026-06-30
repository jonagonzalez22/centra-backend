<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Locality;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'locality_id' => Locality::factory(),
            'street' => $this->faker->streetName(),
            'number' => $this->faker->buildingNumber(),
            'floor' => $this->faker->optional()->randomElement(['PB', '1', '2', '3', '4', '5']),
            'apartment' => $this->faker->optional()->bothify('?#'),
            'postal_code' => $this->faker->postcode(),
            'latitude' => $this->faker->optional(0.7)->latitude(-34.8, -34.4),
            'longitude' => $this->faker->optional(0.7)->longitude(-58.8, -58.0),
            'type' => $this->faker->randomElement(['billing', 'delivery', 'other']),
            'is_main' => false,
            'observations' => $this->faker->optional()->sentence(),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    public function asMain(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
        ]);
    }
}
