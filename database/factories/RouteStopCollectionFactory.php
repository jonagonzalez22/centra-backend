<?php

namespace Database\Factories;

use App\Models\RouteStop;
use App\Models\Store;
use App\Models\CommercialOperation;
use App\Models\StorePaymentMethod;
use App\Models\RouteStopCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStopCollection>
 */
class RouteStopCollectionFactory extends Factory
{
    protected $model = RouteStopCollection::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'route_stop_id' => RouteStop::factory(),
            'commercial_operation_id' => CommercialOperation::factory(),
            'store_payment_method_id' => StorePaymentMethod::factory(),
            'amount' => $this->faker->randomFloat(2, 100, 5000),
            'reference' => $this->faker->optional()->numerify('REF-######'),
            'notes' => $this->faker->optional()->sentence(),
            'declared_by' => null,
            'declared_at' => null,
            'status' => $this->faker->randomElement(['declared', 'verified', 'rejected']),
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
            'operation_payment_id' => null,
        ];
    }

    public function declared(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'declared',
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'verified',
            'verified_by' => $this->faker->uuid(),
            'verified_at' => now(),
        ]);
    }
}
