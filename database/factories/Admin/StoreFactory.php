<?php

namespace Database\Factories\Admin;

use App\Models\Admin\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'name' => $this->faker->company(),
      'cuit' => (string) $this->faker->numerify('20-#########'),
      'address' => $this->faker->streetAddress(),
      'state' => $this->faker->state(),
      'city' => $this->faker->city(),
      'country' => $this->faker->country(),
      'phone' => $this->faker->phoneNumber(),
      'email' => $this->faker->unique()->safeEmail(),
      'is_active' => true,
    ];
  }
}
