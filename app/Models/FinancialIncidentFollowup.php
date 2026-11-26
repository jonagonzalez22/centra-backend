<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FinancialIncidentFollowup extends Model
{
    use HasFactory, HasUuids;

    public const SOURCE_CASH_DIFFERENCE = 'cash_difference';

    public const SOURCE_ROUTE_COLLECTION_REJECTED = 'route_collection_rejected';

    public const STATUSES = ['detected', 'in_review', 'resolved', 'dismissed'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id', 'source_type', 'source_id', 'status', 'assigned_to', 'assigned_at',
        'resolution_type', 'resolution_note', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FinancialIncidentFollowupHistory::class);
    }
}
