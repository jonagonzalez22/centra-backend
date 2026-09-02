<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStopItem>
 */
class RouteStopItemFactory extends Factory
{
    protected $model = RouteStopItem::class;

    public function definition(): array
    {
        return [
            'route_stop_id' => RouteStop::factory(),
            'product_id' => Product::factory(),
            'quantity_planned' => $this->faker->numberBetween(1, 20),
            'quantity_loaded' => 0,
            'quantity_delivered' => 0,
            'quantity_released_for_extra_sale' => 0,
        ];
    }

    public function planned(int $qty): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_planned' => $qty,
            'quantity_loaded' => 0,
            'quantity_delivered' => 0,
        ]);
    }

    public function loaded(int $qty): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_planned' => $qty,
            'quantity_loaded' => $qty,
            'quantity_delivered' => 0,
        ]);
    }
}
