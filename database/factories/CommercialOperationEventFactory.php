<?php

namespace Database\Factories;

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommercialOperationEventFactory extends Factory
{
    protected $model = CommercialOperationEvent::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'operation_id' => CommercialOperation::factory(),
            'event_type' => $this->faker->randomElement(['created', 'reschedule', 'cancel', 'confirm', 'close']),
            'previous_date' => now()->format('Y-m-d'),
            'new_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => $this->faker->randomElement(['created', 'customer_requested_reschedule', 'customer_cancelled', 'completed', 'other']),
            'observation' => null,
            'user_id' => User::factory(),
            'previous_status' => null,
            'new_status' => null,
            'reason_code' => null,
            'reason_note' => null,
        ];
    }
}
