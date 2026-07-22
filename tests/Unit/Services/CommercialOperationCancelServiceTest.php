<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\OperationItem;
use App\Models\Product;
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
    $this->category = Category::factory()->create(['store_id' => $this->store->id]);
    $this->service = new CommercialOperationService();
});

function makeOrderForCancelUnit(Store $store, array $attributes = []): CommercialOperation
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

describe('cancel() — business rule validation', function () {
    it('throws ValidationException when operation type is not order', function () {
        $operation = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);

        expect(fn () => $this->service->cancel(
            $operation,
            'customer_cancelled',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    it('throws ValidationException when status is not open', function () {
        $operation = makeOrderForCancelUnit($this->store, ['status' => 'confirmed']);

        expect(fn () => $this->service->cancel(
            $operation,
            'customer_cancelled',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });

    it('throws ValidationException when status is cancelled', function () {
        $operation = makeOrderForCancelUnit($this->store, ['status' => 'cancelled']);

        expect(fn () => $this->service->cancel(
            $operation,
            'customer_cancelled',
            null,
            $this->user
        ))->toThrow(ValidationException::class);
    });
});

describe('cancel() — happy path with stock release', function () {
    it('cancels order, releases stock_reserved for future delivery, and creates event', function () {
        $operation = makeOrderForCancelUnit($this->store, [
            'requested_delivery_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'stock' => 10,
            'stock_reserved' => 5,
            'price' => 100.00,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $operation->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 100.00,
            'subtotal' => 200.00,
        ]);

        $result = $this->service->cancel(
            $operation,
            'customer_cancelled',
            'Ya no lo necesita',
            $this->user
        );

        expect($result->status)->toBe('cancelled');

        $product->refresh();
        expect($product->stock_reserved)->toBe(3); // 5 - 2

        $this->assertDatabaseHas('commercial_operation_events', [
            'operation_id' => $operation->id,
            'event_type' => 'order_cancelled',
            'previous_status' => 'open',
            'new_status' => 'cancelled',
            'reason_code' => 'customer_cancelled',
            'store_id' => $this->store->id,
        ]);

        $event = \App\Models\CommercialOperationEvent::where('operation_id', $operation->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->reason_note)->toBe('Ya no lo necesita');
    });

    it('cancels same-day order without modifying stock_reserved', function () {
        $operation = makeOrderForCancelUnit($this->store, [
            'requested_delivery_date' => now()->format('Y-m-d'),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'stock' => 10,
            'stock_reserved' => 5,
            'price' => 100.00,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $operation->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 100.00,
            'subtotal' => 200.00,
        ]);

        $result = $this->service->cancel(
            $operation,
            'customer_cancelled',
            null,
            $this->user
        );

        expect($result->status)->toBe('cancelled');

        $product->refresh();
        expect($product->stock_reserved)->toBe(5); // unchanged — same-day delivery
    });
});

describe('cancel() — stock underflow guard', function () {
    it('prevents negative stock_reserved when release exceeds reserved', function () {
        $operation = makeOrderForCancelUnit($this->store, [
            'requested_delivery_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'stock' => 10,
            'stock_reserved' => 2,
            'price' => 100.00,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $operation->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 5,
            'price' => 100.00,
            'subtotal' => 500.00,
        ]);

        $result = $this->service->cancel(
            $operation,
            'out_of_stock',
            null,
            $this->user
        );

        expect($result->status)->toBe('cancelled');

        $product->refresh();
        expect($product->stock_reserved)->toBe(0); // max(0, 2-5) = 0
    });
});

describe('cancel() — event fields', function () {
    it('sets previous_date from operation requested_delivery_date and new_date to null', function () {
        $operation = makeOrderForCancelUnit($this->store, [
            'requested_delivery_date' => '2026-07-25',
        ]);

        $result = $this->service->cancel(
            $operation,
            'duplicate_order',
            null,
            $this->user
        );

        $event = \App\Models\CommercialOperationEvent::where('operation_id', $operation->id)->first();

        expect($event)->not->toBeNull()
            ->and($event->previous_date->format('Y-m-d'))->toBe('2026-07-25')
            ->and($event->new_date)->toBeNull()
            ->and($event->reason_code)->toBe('duplicate_order')
            ->and($event->reason_note)->toBeNull();
    });

    it('stores reason_note when provided', function () {
        $operation = makeOrderForCancelUnit($this->store, [
            'requested_delivery_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $result = $this->service->cancel(
            $operation,
            'other',
            'Motivo detallado de cancelación',
            $this->user
        );

        $event = \App\Models\CommercialOperationEvent::where('operation_id', $operation->id)->first();

        expect($event->reason_code)->toBe('other');
        expect($event->reason_note)->toBe('Motivo detallado de cancelación');
    });
});
