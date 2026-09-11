<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashSession extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'user_id',
        'status',
        'business_date',
        'opening_amount',
        'expected_amount',
        'real_amount',
        'declared_amount',
        'notes',
        'declaration_notes',
        'reconciliation_notes',
        'opened_at',
        'submitted_at',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'real_amount' => 'decimal:2',
        'declared_amount' => 'decimal:2',
        'business_date' => 'date',
        'opened_at' => 'datetime',
        'submitted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OperationPayment::class);
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeOperational(Builder $query, string $userId, string $businessDate): Builder
    {
        return $query->where('user_id', $userId)
            ->where('status', 'open')
            ->whereDate('business_date', $businessDate);
    }
}
