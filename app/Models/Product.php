<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'description',
        'price',
        'cost',
        'stock',
        'stock_reserved',
        'stock_min',
        'parent_product_id',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'stock' => 'integer',
        'stock_reserved' => 'integer',
        'stock_min' => 'integer',
        'is_active' => 'boolean',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->stock - $this->stock_reserved);
    }

    public static function validateStockIntegrity(int $stock, int $stockReserved): bool
    {
        return $stockReserved <= $stock;
    }
}
