<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class BrandFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => Store::factory(), 'name' => $this->faker->unique()->company(), 'is_active' => true];
    }
}
