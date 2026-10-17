<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteStopItem extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'route_stop_id',
        'product_id',
        // Immutable physical stock-consumption snapshots. Route stop quantities
        // remain expressed in the commercial product's measurement unit.
        'stock_source_product_id',
        'stock_consumption_quantity',
        'stock_source_measurement_unit_code',
        'stock_source_measurement_unit_symbol',
        'quantity_planned',
        'quantity_loaded',
        'quantity_delivered',
        'quantity_released_for_extra_sale',
        'is_extra',
    ];

    protected function casts(): array
    {
        return [
            'stock_consumption_quantity' => 'decimal:4',
            'quantity_planned' => 'decimal:4',
            'quantity_loaded' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quantity_released_for_extra_sale' => 'decimal:4',
            'is_extra' => 'boolean',
        ];
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockSourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_source_product_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RouteLoadAdjustment::class, 'route_stop_item_id');
    }

    public function discrepancy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeliveryDiscrepancy::class, 'route_stop_item_id');
    }
}
