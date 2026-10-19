<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierProduct extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'supplier_id',
        'product_id',
        'supplier_code',
        'supplier_product_name',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $supplierProduct) => $supplierProduct->id ??= (string) Str::uuid());

        static::saving(function (self $supplierProduct) {
            foreach (['supplier_code', 'supplier_product_name'] as $field) {
                if ($supplierProduct->{$field} !== null) {
                    $value = trim($supplierProduct->{$field});
                    $supplierProduct->{$field} = $value === '' ? null : $value;
                }
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
