<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryMovement extends Model
{
  use HasFactory;

  protected $keyType = 'string';

  public $incrementing = false;

  protected $fillable = [
    'store_id',
    'product_id',
    'user_id',
    'type',
    'quantity',
    'previous_stock',
    'current_stock',
    'concept',
  ];

  protected function casts(): array
  {
    return [
      'quantity' => 'integer',
      'previous_stock' => 'integer',
      'current_stock' => 'integer',
    ];
  }

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

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function scopeForStore(Builder $query, string $storeId): Builder
  {
    return $query->where('store_id', $storeId);
  }
}
