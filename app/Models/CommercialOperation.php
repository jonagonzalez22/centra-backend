<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommercialOperation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'branch_id',
        'user_id',
        'customer_id',
        'operation_number',
        'type',
        'status',
        'subtotal',
        'tax',
        'discount',
        'total',
        'requested_delivery_date',
        'completed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'requested_delivery_date' => 'date',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OperationItem::class, 'operation_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OperationPayment::class, 'operation_id');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeBetweenDates(Builder $query, ?string $dateFrom, ?string $dateTo): Builder
    {
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    public static function generateNumber(string $type, string $storeId): string
    {
        if (! in_array($type, ['sale', 'order'])) {
            throw new \InvalidArgumentException('Type must be sale or order');
        }

        return DB::transaction(function () use ($type, $storeId) {
            $counter = CommercialOperationCounter::where('store_id', $storeId)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                try {
                    $counter = CommercialOperationCounter::create([
                        'store_id' => $storeId,
                        'type' => $type,
                        'last_number' => 0,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->errorInfo[1] === 1062) {
                        $counter = CommercialOperationCounter::where('store_id', $storeId)
                            ->where('type', $type)
                            ->lockForUpdate()
                            ->firstOrFail();
                    } else {
                        throw $e;
                    }
                }
            }

            $counter->last_number = $counter->last_number + 1;
            $counter->save();

            $prefix = $type === 'sale' ? 'V' : 'P';

            return $prefix.'-'.str_pad($counter->last_number, 6, '0', STR_PAD_LEFT);
        });
    }
}
