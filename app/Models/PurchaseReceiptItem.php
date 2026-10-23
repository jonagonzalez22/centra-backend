<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseReceiptItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_item_id',
        'product_id',
        'product_name_snapshot',
        'product_sku_snapshot',
        'measurement_unit_code_snapshot',
        'measurement_unit_symbol_snapshot',
        'quantity_received',
    ];

    protected $casts = ['quantity_received' => 'decimal:4'];

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->id ??= (string) Str::uuid());
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
