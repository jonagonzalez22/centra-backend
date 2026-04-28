<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
  protected $model = Plan::class;

  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'name' => $this->faker->word(),
      'description' => $this->faker->sentence(),
      'price' => $this->faker->numberBetween(100, 10000),
      'billing_cycle' => 'monthly',
      'is_active' => true,
      'is_trial' => false,
    ];
  }
}
