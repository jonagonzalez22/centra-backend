<?php

namespace Database\Factories;

use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryRoute>
 */
class DeliveryRouteFactory extends Factory
{
    protected $model = DeliveryRoute::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'vehicle_id' => Vehicle::factory(),
            'driver_id' => User::factory(),
            'operational_date' => $this->faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'status' => 'draft',
            'observations' => $this->faker->optional()->sentence(),
            'created_by' => null,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function planned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'planned',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
