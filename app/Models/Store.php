<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;

    private static array $requestFeatureCache = [];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'business_type_id',
        'plan_id',
        'cuit',
        'address',
        'state',
        'city',
        'country',
        'phone',
        'email',
        'url_logo',
        'is_active',
        'inactive_reason',
        'inactive_at',
        'latitude',
        'longitude',
        'timezone',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'inactive_at' => 'datetime',
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

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function settings()
    {
        return $this->hasOne(StoreSetting::class);
    }

    public function getUnloadTimeMinutes(): int
    {
        if ($this->relationLoaded('settings') && $this->settings) {
            return $this->settings->delivery_unload_time_minutes;
        }

        return $this->settings()->value('delivery_unload_time_minutes') ?? 15;
    }

    public function hasFeature(string $code): bool
    {
        $cacheKey = "{$this->id}:{$code}";

        if (array_key_exists($cacheKey, self::$requestFeatureCache)) {
            return self::$requestFeatureCache[$cacheKey];
        }

        if (! $this->plan) {
            return self::$requestFeatureCache[$cacheKey] = false;
        }

        $this->loadMissing('plan.features');

        return self::$requestFeatureCache[$cacheKey] = $this->plan->features->contains('code', $code);
    }

    public static function clearFeatureCache(): void
    {
        self::$requestFeatureCache = [];
    }

    public function getFeatureLimit(string $code): ?int
    {
        if (! $this->plan || ! $this->plan->relationLoaded('features')) {
            return null;
        }

        $feature = $this->plan->features->firstWhere('code', $code);

        return $feature?->pivot?->limit_value;
    }

    public function canUseFeature(string $code, int $currentUsage): bool
    {
        if (! $this->hasFeature($code)) {
            return false;
        }

        $limit = $this->getFeatureLimit($code);

        if (is_null($limit)) {
            return true;
        }

        return $currentUsage < $limit;
    }
}
