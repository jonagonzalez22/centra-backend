<?php

namespace Database\Factories;

use App\Models\CommercialOperation;
use App\Models\OperationItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperationItemFactory extends Factory
{
    protected $model = OperationItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 10);
        $price = $this->faker->randomFloat(2, 10, 500);
        $subtotal = $quantity * $price;
        $taxAmount = $subtotal * 0.21;
        $discountAmount = 0;

        return [
            'operation_id' => CommercialOperation::factory(),
            'product_id' => Product::factory(),
            'product_name' => $this->faker->words(3, true),
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
        ];
    }

    public function forOperation(CommercialOperation $operation): static
    {
        return $this->state(fn (array $attributes) => [
            'operation_id' => $operation->id,
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
    }
}
