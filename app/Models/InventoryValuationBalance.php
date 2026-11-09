<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryValuationBalance extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'stock_source_product_id', 'quantity', 'known_quantity', 'unknown_quantity', 'total_value', 'average_unit_cost', 'cost_status'];

    protected $casts = [
        'quantity' => 'decimal:4', 'known_quantity' => 'decimal:4', 'unknown_quantity' => 'decimal:4',
        'total_value' => 'decimal:6', 'average_unit_cost' => 'decimal:6',
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
}
