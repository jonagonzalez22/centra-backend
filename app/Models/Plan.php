<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'name',
    'description',
    'price',
    'billing_cycle',
    'is_active',
    'is_trial',
  ];

  protected $casts = [
    'price'       => 'decimal:2',
    'is_active'   => 'boolean',
    'is_trial'    => 'boolean',
  ];

  public function features()
  {
    return $this->belongsToMany(Feature::class, 'plan_features')
      ->withPivot('limit_value')
      ->withTimestamps();
  }
}
