<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => $this->faker->word() . ' Vehicle',
            'plate' => strtoupper($this->faker->unique()->bothify('???###')),
            'type' => $this->faker->randomElement(['auto', 'moto', 'bicicleta', 'camioneta', 'camion']),
            'capacity_kg' => $this->faker->optional()->numberBetween(10, 5000),
            'is_active' => true,
            'inactivation_reason' => null,
            'inactivation_notes' => null,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'inactivation_reason' => 'maintenance',
            'inactivation_notes' => null,
        ]);
    }
}
