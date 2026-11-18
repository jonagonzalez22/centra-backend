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
        'customer_address_id',
        'destination_street',
        'destination_number',
        'destination_floor',
        'destination_apartment',
        'destination_postal_code',
        'destination_locality_id',
        'destination_locality_name',
        'destination_latitude',
        'destination_longitude',
        'destination_observations',
        'sequence',
        'status',
        'logistics_notes',
        'estimated_arrival_at',
        'travel_duration_seconds',
        'completed_by',
        'completed_at',
        'gps_lat',
        'gps_lon',
        'signature_uri',
        'evidence_uris',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_arrival_at' => 'datetime',
            'travel_duration_seconds' => 'integer',
            'completed_at' => 'datetime',
            'notified_at' => 'datetime',
            'gps_lat' => 'decimal:7',
            'gps_lon' => 'decimal:7',
            'destination_latitude' => 'decimal:8',
            'destination_longitude' => 'decimal:8',
            'evidence_uris' => 'array',
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

    public function destinationAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }

    public function hasDestinationSnapshot(): bool
    {
        return $this->customer_address_id !== null;
    }

    /** @return array<string, mixed>|null */
    public function destinationSnapshot(): ?array
    {
        if (! $this->hasDestinationSnapshot()) {
            return null;
        }

        return [
            'street' => $this->destination_street,
            'number' => $this->destination_number,
            'floor' => $this->destination_floor,
            'apartment' => $this->destination_apartment,
            'postal_code' => $this->destination_postal_code,
            'locality_id' => $this->destination_locality_id,
            'locality' => $this->destination_locality_name,
            'latitude' => $this->destination_latitude === null ? null : (float) $this->destination_latitude,
            'longitude' => $this->destination_longitude === null ? null : (float) $this->destination_longitude,
            'reference' => $this->destination_observations,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RouteStopItem::class, 'route_stop_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(RouteStopCollection::class, 'route_stop_id');
    }

    public function saleAllocations(): HasMany
    {
        return $this->hasMany(DeliverySaleAllocation::class, 'route_stop_id');
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
