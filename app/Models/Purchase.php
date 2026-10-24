<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'supplier_id', 'purchase_number', 'purchase_type', 'status', 'supplier_business_name', 'supplier_tax_id', 'notes', 'total', 'ordered_at', 'cancelled_at', 'closed_at', 'closed_by', 'created_by', 'updated_by'];

    protected $casts = ['total' => 'decimal:2', 'ordered_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $purchase) => $purchase->id ??= (string) Str::uuid());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderBy('received_at')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PurchaseDocument::class)->orderByDesc('issued_at')->orderByDesc('created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
