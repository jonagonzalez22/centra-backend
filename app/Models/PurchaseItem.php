<?php

namespace App\Models;

use App\Support\QuantityMath;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['purchase_id', 'product_id', 'product_name', 'product_sku', 'supplier_code', 'supplier_product_name', 'measurement_unit_code', 'measurement_unit_symbol', 'quantity_ordered', 'quantity_received', 'unit_price', 'subtotal'];

    protected $casts = ['quantity_ordered' => 'decimal:4', 'quantity_received' => 'decimal:4', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->id ??= (string) Str::uuid());
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getQuantityPendingAttribute(): string
    {
        return QuantityMath::subtract($this->quantity_ordered, $this->quantity_received);
    }
}
