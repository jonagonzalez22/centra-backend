<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliverySaleAllocation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_delivered' => 'decimal:4',
            'unit_price_snapshot' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'is_extra' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }

    public function routeStopItem(): BelongsTo
    {
        return $this->belongsTo(RouteStopItem::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CommercialOperation::class, 'operation_id');
    }

    public function operationItem(): BelongsTo
    {
        return $this->belongsTo(OperationItem::class, 'operation_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
