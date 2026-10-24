<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseDocument extends Model
{
    use HasUuids;

    public const TYPES = ['invoice', 'delivery_note', 'credit_note', 'debit_note', 'other'];

    public const AMOUNT_REQUIRED_TYPES = ['invoice', 'credit_note', 'debit_note'];

    public const PURCHASE_DOCUMENTABLE_STATUSES = ['ordered', 'partially_received', 'received', 'closed'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'purchase_id', 'type', 'number', 'issued_at', 'total_amount', 'notes', 'created_by'];

    protected $casts = ['issued_at' => 'date:Y-m-d', 'total_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(fn (self $document) => $document->id ??= (string) Str::uuid());
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
