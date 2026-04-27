<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'code',
    'name',
    'description',
  ];
  public function plans()
  {
    return $this->belongsToMany(Plan::class, 'plan_features')
      ->withPivot('limit_value')
      ->withTimestamps();
  }
}
