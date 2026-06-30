<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Province extends Model
{
    use HasFactory;

    protected $table = 'geography_provinces';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'iso_code',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function localities(): HasMany
    {
        return $this->hasMany(Locality::class);
    }
}
