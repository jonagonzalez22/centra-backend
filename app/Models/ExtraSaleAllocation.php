<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtraSaleAllocation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'route_id',
        'destination_stop_id',
        'destination_stop_item_id',
        'source_stop_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
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

    public function destinationStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'destination_stop_id');
    }

    public function destinationStopItem(): BelongsTo
    {
        return $this->belongsTo(RouteStopItem::class, 'destination_stop_item_id');
    }

    public function sourceStopItem(): BelongsTo
    {
        return $this->belongsTo(RouteStopItem::class, 'source_stop_item_id');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForRoute(Builder $query, string $routeId): Builder
    {
        return $query->where('route_id', $routeId);
    }
}
