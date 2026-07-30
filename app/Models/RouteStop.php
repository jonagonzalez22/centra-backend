<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteStop extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'route_id',
        'order_id',
        'sequence',
        'status',
        'logistics_notes',
        'estimated_arrival_at',
        'travel_duration_seconds',
        'completed_by',
        'completed_at',
        'gps_lat',
        'gps_lon',
    ];

    protected function casts(): array
    {
        return [
            'estimated_arrival_at' => 'datetime',
            'travel_duration_seconds' => 'integer',
            'completed_at' => 'datetime',
            'gps_lat' => 'decimal:7',
            'gps_lon' => 'decimal:7',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommercialOperation::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RouteStopItem::class, 'route_stop_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }
}
