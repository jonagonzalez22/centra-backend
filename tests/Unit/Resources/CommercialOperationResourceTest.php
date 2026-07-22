<?php

declare(strict_types=1);

use App\Http\Resources\CommercialOperationResource;
use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

describe('CommercialOperationResource', function () {
    test('returns delivery_date from requested_delivery_date attribute', function () {
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
        $data = $resource->toArray(new Request());

        expect($data)->toHaveKey('delivery_date')
            ->and($data['delivery_date'])->toBe('2026-07-25');
    });

    test('delivery_date is null when requested_delivery_date is null', function () {
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
        $data = $resource->toArray(new Request());

        expect($data['delivery_date'])->toBeNull();
    });
});
