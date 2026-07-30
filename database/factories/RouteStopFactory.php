<?php

namespace Database\Factories;

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStop>
 */
class RouteStopFactory extends Factory
{
    protected $model = RouteStop::class;

    public function definition(): array
    {
        return [
            'route_id' => DeliveryRoute::factory(),
            'order_id' => CommercialOperation::factory(),
            'sequence' => $this->faker->numberBetween(1, 20),
            'status' => 'pending',
            'logistics_notes' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
