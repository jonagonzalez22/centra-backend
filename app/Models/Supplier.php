<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Supplier extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'business_name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->id ??= (string) Str::uuid());
        static::saving(function (self $s) {
            foreach (['business_name', 'tax_id', 'contact_name', 'phone', 'email', 'address'] as $field) {
                if ($s->{$field} !== null) {
                    $s->{$field} = trim($s->{$field});
                }
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForStore(Builder $q, string $storeId): Builder
    {
        return $q->where('store_id', $storeId);
    }
}
