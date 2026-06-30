<?php

namespace Database\Factories;

use App\Models\Locality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalityFactory extends Factory
{
    protected $model = Locality::class;

    public function definition(): array
    {
        return [
            'province_id' => Province::factory(),
            'name' => $this->faker->city(),
            'zip_code' => $this->faker->postcode(),
        ];
    }
}
