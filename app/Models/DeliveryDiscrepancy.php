<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryDiscrepancy extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'route_stop_item_id',
        'product_id',
        'quantity_loaded',
        'quantity_delivered',
        'difference_quantity',
        'resolution_type',
        'notes',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_loaded' => 'integer',
            'quantity_delivered' => 'integer',
            'difference_quantity' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function routeStopItem(): BelongsTo
    {
        return $this->belongsTo(RouteStopItem::class, 'route_stop_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolution_type');
    }
}
