<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryValuationEntry extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'stock_source_product_id', 'type', 'reference_type', 'reference_id', 'quantity', 'unit_cost', 'total_cost', 'cost_status', 'occurred_at', 'created_by'];

    protected $casts = [
        'quantity' => 'decimal:4', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6', 'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->id ??= (string) Str::uuid());
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockSourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_source_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
