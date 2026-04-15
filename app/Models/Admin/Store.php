<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
  use HasFactory;

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

  public function users(): HasMany
  {
    return $this->hasMany(User::class);
  }

  public function businessType(): BelongsTo
  {
    return $this->belongsTo(BusinessType::class);
  }
}
