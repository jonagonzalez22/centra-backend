<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteLoadAdjustment extends Model
{
    use HasFactory, HasUuids;

    const UPDATED_AT = null;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'route_stop_item_id',
        'user_id',
        'old_quantity',
        'new_quantity',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'old_quantity' => 'integer',
            'new_quantity' => 'integer',
        ];
    }

    public function routeStopItem(): BelongsTo
    {
        return $this->belongsTo(RouteStopItem::class, 'route_stop_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
