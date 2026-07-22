<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\CommercialOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->service = new CommercialOperationService();
});

function makeOrder(Store $store, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => User::factory()->create(['store_id' => $store->id])->id,
        'customer_id' => Customer::factory()->create(['store_id' => $store->id])->id,
        'type' => 'order',
        'status' => 'open',
        'requested_delivery_date' => now()->addDays(5)->format('Y-m-d'),
    ], $attributes));
}

describe('rescheduleDeliveryDate', function () {
    test('throws exception when operation type is not order', function () {
        $operation = makeOrder($this->store, ['type' => 'sale', 'status' => 'confirmed']);

        expect(fn () => $this->service->rescheduleDeliveryDate(
            $operation,
            now()->addDays(10)->format('Y-m-d'),
            'customer_requested_reschedule',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    test('throws exception when status is not open or confirmed', function () {
        $operation = makeOrder($this->store, ['status' => 'cancelled']);

        expect(fn () => $this->service->rescheduleDeliveryDate(
            $operation,
            now()->addDays(10)->format('Y-m-d'),
            'customer_requested_reschedule',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    test('throws exception when requested_delivery_date is null', function () {
        $operation = makeOrder($this->store, ['requested_delivery_date' => null]);

        expect(fn () => $this->service->rescheduleDeliveryDate(
            $operation,
            now()->addDays(10)->format('Y-m-d'),
            'customer_requested_reschedule',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    test('throws exception when new_date equals current date', function () {
        $date = now()->addDays(5)->format('Y-m-d');
        $operation = makeOrder($this->store, ['requested_delivery_date' => $date]);

        expect(fn () => $this->service->rescheduleDeliveryDate(
            $operation,
            $date,
            'customer_requested_reschedule',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    test('creates event and updates date within transaction', function () {
        $operation = makeOrder($this->store, [
            'requested_delivery_date' => '2026-07-25',
        ]);

        $newDate = '2026-07-30';

        $result = $this->service->rescheduleDeliveryDate(
            $operation,
            $newDate,
            'customer_requested_reschedule',
            'Customer asked to move it',
            $this->user
        );

        expect($result->requested_delivery_date->format('Y-m-d'))->toBe($newDate);

        $this->assertDatabaseHas('commercial_operation_events', [
            'operation_id' => $operation->id,
            'event_type' => 'delivery_date_changed',
            'reason' => 'customer_requested_reschedule',
            'store_id' => $this->store->id,
        ]);

        $event = \App\Models\CommercialOperationEvent::where('operation_id', $operation->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->previous_date->format('Y-m-d'))->toBe('2026-07-25')
            ->and($event->new_date->format('Y-m-d'))->toBe('2026-07-30');
    });

    test('allows reschedule for confirmed orders', function () {
        $operation = makeOrder($this->store, [
            'status' => 'confirmed',
            'requested_delivery_date' => '2026-07-25',
        ]);

        $result = $this->service->rescheduleDeliveryDate(
            $operation,
            '2026-08-01',
            'operational_issue',
            null,
            $this->user
        );

        expect($result->requested_delivery_date->format('Y-m-d'))->toBe('2026-08-01');
    });
});
