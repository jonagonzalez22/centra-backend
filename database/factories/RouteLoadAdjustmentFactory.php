<?php

namespace Database\Factories;

use App\Models\RouteLoadAdjustment;
use App\Models\RouteStopItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteLoadAdjustment>
 */
class RouteLoadAdjustmentFactory extends Factory
{
    protected $model = RouteLoadAdjustment::class;

    public function definition(): array
    {
        return [
            'route_stop_item_id' => RouteStopItem::factory(),
            'user_id' => User::factory(),
            'old_quantity' => $this->faker->numberBetween(1, 10),
            'new_quantity' => $this->faker->numberBetween(0, 10),
            'reason' => $this->faker->randomElement(['no_stock', 'product_damaged', 'product_not_found', 'space_limit', 'other']),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
