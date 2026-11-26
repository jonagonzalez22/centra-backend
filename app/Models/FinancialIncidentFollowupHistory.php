<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FinancialIncidentFollowupHistory extends Model
{
    use HasFactory, HasUuids;

    const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'financial_incident_followup_id', 'action', 'actor_id', 'previous_status', 'new_status',
        'previous_assigned_to', 'new_assigned_to', 'comment', 'metadata',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(FinancialIncidentFollowup::class, 'financial_incident_followup_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function previousAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_assigned_to');
    }

    public function newAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_assigned_to');
    }
}
