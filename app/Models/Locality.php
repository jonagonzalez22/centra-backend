<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Locality extends Model
{
    use HasFactory;

    protected $table = 'geography_localities';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'province_id',
        'name',
        'zip_code',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
