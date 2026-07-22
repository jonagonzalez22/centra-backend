<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialOperationEvent extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'store_id',
        'operation_id',
        'event_type',
        'previous_date',
        'new_date',
        'reason',
        'observation',
        'user_id',
        'previous_status',
        'new_status',
        'reason_code',
        'reason_note',
    ];

    protected function casts(): array
    {
        return [
            'previous_date' => 'date',
            'new_date' => 'date',
            'created_at' => 'datetime',
            'previous_status' => 'string',
            'new_status' => 'string',
            'reason_code' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CommercialOperation::class, 'operation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
