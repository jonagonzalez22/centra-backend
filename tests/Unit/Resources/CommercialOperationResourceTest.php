<?php

declare(strict_types=1);

use App\Http\Resources\CommercialOperationListResource;
use App\Http\Resources\CommercialOperationResource;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

describe('CommercialOperationResource', function () {
    test('returns requested_delivery_date from requested_delivery_date attribute', function () {
        $store = Store::factory()->create();
        $customer = Customer::factory()->create(['store_id' => $store->id]);
        $user = User::factory()->create(['store_id' => $store->id]);

        $operation = CommercialOperation::factory()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'type' => 'order',
            'status' => 'open',
            'requested_delivery_date' => '2026-07-25',
        ]);

        $resource = CommercialOperationResource::make($operation);
        $data = $resource->toArray(new Request);

        expect($data)->toHaveKey('requested_delivery_date')
            ->and($data['requested_delivery_date'])->toBe('2026-07-25');
    });

    test('requested_delivery_date is null when requested_delivery_date is null', function () {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);

        $operation = CommercialOperation::factory()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'type' => 'sale',
            'status' => 'confirmed',
            'requested_delivery_date' => null,
        ]);

        $resource = CommercialOperationResource::make($operation);
        $data = $resource->toArray(new Request);

        expect($data['requested_delivery_date'])->toBeNull();
    });

    test('omits zero quantity lines and combines equivalent current lines of the same product', function () {
        [$operation, $product] = commercialOperationWithProductItems([
            ['quantity' => 5, 'price' => 4800],
            ['quantity' => 3, 'price' => 4800],
            ['quantity' => 0, 'price' => 4800],
        ], 38400);

        $data = CommercialOperationResource::make($operation->load('items'))->toArray(new Request);

        expect($data['items'])->toHaveCount(1)
            ->and($data['items'][0]['id'])->toBe(OperationItem::where('operation_id', $operation->id)->orderBy('created_at')->firstOrFail()->id)
            ->and($data['items'][0]['product_id'])->toBe($product->id)
            ->and($data['items'][0]['quantity'])->toBe(8)
            ->and($data['items'][0]['price'])->toBe(4800.0)
            ->and($data['items'][0]['subtotal'])->toBe(38400.0)
            ->and($data['total'])->toBe(38400.0)
            ->and($data['delivery_summary']['items'][0]['ordered_quantity'])->toBe(8);

        $listData = CommercialOperationListResource::make($operation->load('items'))->toArray(new Request);
        expect($listData['items_count'])->toBe(1);
    });

    test('keeps lines with different commercial terms separate instead of exposing a false price', function () {
        [$operation] = commercialOperationWithProductItems([
            ['quantity' => 5, 'price' => 4800],
            ['quantity' => 3, 'price' => 5200],
        ], 39600);

        $data = CommercialOperationResource::make($operation->load('items'))->toArray(new Request);

        expect($data['items'])->toHaveCount(2)
            ->and(collect($data['items'])->pluck('quantity')->all())->toBe([5, 3])
            ->and(collect($data['items'])->pluck('price')->all())->toBe([4800.0, 5200.0])
            ->and(collect($data['items'])->pluck('subtotal')->all())->toBe([24000.0, 15600.0])
            ->and($data['total'])->toBe(39600.0);

        $listData = CommercialOperationListResource::make($operation->load('items'))->toArray(new Request);
        expect($listData['items_count'])->toBe(1);
    });
});

function commercialOperationWithProductItems(array $items, float $total): array
{
    $store = Store::factory()->create();
    $customer = Customer::factory()->create(['store_id' => $store->id]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $product = Product::factory()->create(['store_id' => $store->id, 'price' => 4800]);
    $operation = CommercialOperation::factory()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'open',
        'subtotal' => $total,
        'total' => $total,
    ]);

    foreach ($items as $item) {
        OperationItem::factory()->create([
            'operation_id' => $operation->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'subtotal' => $item['quantity'] * $item['price'],
            'tax_amount' => 0,
            'discount_amount' => 0,
        ]);
    }

    return [$operation, $product];
}
