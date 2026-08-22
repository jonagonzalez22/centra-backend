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
        'quantity_planned',
        'quantity_loaded',
        'quantity_delivered',
        'is_extra',
    ];

    protected function casts(): array
    {
        return [
            'quantity_planned' => 'integer',
            'quantity_loaded' => 'integer',
            'quantity_delivered' => 'integer',
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

    public function adjustments(): HasMany
    {
        return $this->hasMany(RouteLoadAdjustment::class, 'route_stop_item_id');
    }

    public function discrepancy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeliveryDiscrepancy::class, 'route_stop_item_id');
    }
}
