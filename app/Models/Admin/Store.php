<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
  use HasFactory;

  protected $keyType = 'string';
  public $incrementing = false;

  protected $fillable = [
    'name',
    'business_type_id',
    'cuit',
    'address',
    'state',
    'city',
    'country',
    'phone',
    'email',
    'url_logo',
    'status',
  ];

  protected $casts = [
    'status' => 'string',
  ];

  protected static function booted()
  {
    static::creating(function ($model) {
      if (! $model->id) {
        $model->id = (string) Str::uuid();
      }
    });
  }

  public function users(): HasMany
  {
    return $this->hasMany(User::class);
  }

  public function businessType(): BelongsTo
  {
    return $this->belongsTo(BusinessType::class);
  }
}
