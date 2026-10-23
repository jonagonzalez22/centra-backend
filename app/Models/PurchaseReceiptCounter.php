<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurchaseReceiptCounter extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['store_id', 'last_number'];

    protected static function booted(): void
    {
        static::creating(fn (self $counter) => $counter->id ??= (string) Str::uuid());
    }
}
