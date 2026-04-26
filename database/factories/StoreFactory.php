<?php

namespace Database\Factories;

use App\Models\BusinessType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
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
      'business_type_id' => BusinessType::factory(),
      'cuit' => (string) $this->faker->numerify('20-#########'),
      'address' => $this->faker->streetAddress(),
      'state' => $this->faker->state(),
      'city' => $this->faker->city(),
      'country' => $this->faker->country(),
      'phone' => $this->faker->phoneNumber(),
      'email' => $this->faker->unique()->safeEmail(),
      'url_logo' => null,
      'is_active' => true,
      'inactive_reason' => null,
      'inactive_at' => null,
    ];
  }
}
