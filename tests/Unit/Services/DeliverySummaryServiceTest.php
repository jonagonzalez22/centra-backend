<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Services\DeliverySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

it('derives pending delivery by product and active route assignment', function () {
    $product = new Product(['id' => 'product-1', 'name' => 'Cinta', 'sku' => 'C-1']);
    $line = new OperationItem([
        'product_id' => $product->id,
        'product_name' => 'Cinta',
        'quantity' => 2,
    ]);
    $line->setRelation('product', $product);

    $completedRoute = new DeliveryRoute(['id' => 'route-completed', 'status' => 'completed']);
    $completedStop = new RouteStop(['id' => 'stop-completed', 'status' => 'completed']);
    $completedStop->setRelation('route', $completedRoute);
    $deliveredItem = new RouteStopItem([
        'product_id' => $product->id,
        'quantity_delivered' => 1,
        'quantity_planned' => 2,
    ]);
    $completedStop->setRelation('items', collect([$deliveredItem]));

    $activeRoute = new DeliveryRoute(['id' => 'route-planned', 'status' => 'planned']);
    $activeStop = new RouteStop(['id' => 'stop-planned', 'status' => 'pending']);
    $activeStop->setRelation('route', $activeRoute);
    $plannedItem = new RouteStopItem([
        'product_id' => $product->id,
        'quantity_delivered' => 0,
        'quantity_planned' => 1,
    ]);
    $activeStop->setRelation('items', collect([$plannedItem]));

    $operation = new CommercialOperation(['type' => 'order']);
    $operation->setRelation('items', collect([$line]));
    $operation->setRelation('routeStops', collect([$completedStop, $activeStop]));

    $summary = app(DeliverySummaryService::class)->summarize($operation);

    expect($summary['has_pending_delivery'])->toBeTrue()
        ->and($summary['pending_delivery_quantity'])->toBe(1)
        ->and($summary['items'][0])->toMatchArray([
            'ordered_quantity' => 2,
            'delivered_quantity' => 1,
            'pending_quantity' => 1,
            'planned_active_quantity' => 1,
            'unassigned_pending_quantity' => 0,
        ]);
});

it('excludes completed routes from active planning and clamps pending at zero', function () {
    $product = new Product(['id' => 'product-2', 'name' => 'Rodillo', 'sku' => 'R-1']);
    $line = new OperationItem(['product_id' => $product->id, 'product_name' => 'Rodillo', 'quantity' => 1]);
    $line->setRelation('product', $product);

    $route = new DeliveryRoute(['status' => 'completed']);
    $stop = new RouteStop(['status' => 'completed']);
    $stop->setRelation('route', $route);
    $stop->setRelation('items', collect([new RouteStopItem([
        'product_id' => $product->id,
        'quantity_delivered' => 2,
        'quantity_planned' => 2,
    ])]));

    $operation = new CommercialOperation(['type' => 'order']);
    $operation->setRelation('items', collect([$line]));
    $operation->setRelation('routeStops', collect([$stop]));

    $item = app(DeliverySummaryService::class)->summarize($operation)['items'][0];

    expect($item['pending_quantity'])->toBe(0)
        ->and($item['planned_active_quantity'])->toBe(0)
        ->and($item['unassigned_pending_quantity'])->toBe(0);
});
