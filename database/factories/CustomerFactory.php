<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'display_name' => $this->faker->name(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'company_name' => $this->faker->optional()->company(),
            'document_type_id' => DocumentType::factory(),
            'document_number' => $this->faker->numerify('##-########-#'),
            'status' => 'active',
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $store->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
