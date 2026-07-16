<?php

namespace Database\Factories;

use App\Models\CommercialOperationCounter;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommercialOperationCounterFactory extends Factory
{
    protected $model = CommercialOperationCounter::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'type' => 'sale',
            'last_number' => 0,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sale',
        ]);
    }

    public function order(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'order',
        ]);
    }
}
