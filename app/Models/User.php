<?php

namespace App\Models;

use App\Models\Admin\Store;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class User extends Authenticatable
{
  use HasApiTokens, HasFactory, Notifiable, HasRoles;

  protected $keyType = 'string';
  public $incrementing = false;


  protected $fillable = [
    'name',
    'email',
    'password',
    'store_id',
  ];

  protected $hidden = [
    'password',
    'remember_token',
  ];

  protected function casts(): array
  {
    return [
      'email_verified_at' => 'datetime',
      'password' => 'hashed',
    ];
  }


  protected static function booted()
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
}
