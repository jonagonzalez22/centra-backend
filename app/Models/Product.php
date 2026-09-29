<?php

namespace App\Models;

use App\Support\QuantityMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'description',
        'price',
        'cost',
        'stock',
        'stock_reserved',
        'stock_min',
        'stock_measurement_unit_id',
        'sale_quantity_step',
        'parent_product_id',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'stock' => 'decimal:4',
        'stock_reserved' => 'decimal:4',
        'stock_min' => 'decimal:4',
        'sale_quantity_step' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }

            if (! $model->stock_measurement_unit_id) {
                $model->stock_measurement_unit_id = MeasurementUnit::query()
                    ->active()
                    ->where('code', 'unit')
                    ->value('id');
            }

            if (! $model->stock_measurement_unit_id) {
                throw new \RuntimeException('No existe una unidad de medida predeterminada activa.');
            }

            if ($model->sale_quantity_step === null) {
                $model->sale_quantity_step = '1.0000';
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stockMeasurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'stock_measurement_unit_id');
    }

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function getAvailableStockAttribute(): string
    {
        return QuantityMath::max('0', QuantityMath::subtract($this->stock, $this->stock_reserved));
    }

    public function getCommercialAvailableQuantityAttribute(): string
    {
        return QuantityMath::floorToMultiple(
            $this->available_stock,
            $this->sale_quantity_step ?? '1.0000'
        );
    }

    public function commercialQuantityError(int|string $quantity): ?string
    {
        $normalizedQuantity = QuantityMath::normalize($quantity);
        $step = QuantityMath::normalize($this->sale_quantity_step ?? '1.0000');

        if (! QuantityMath::isPositive($normalizedQuantity)) {
            return 'La cantidad debe ser mayor a cero.';
        }

        if (! QuantityMath::isMultipleOf($normalizedQuantity, $step)) {
            $displayStep = rtrim(rtrim($step, '0'), '.');

            return 'La cantidad debe ser múltiplo de '.str_replace('.', ',', $displayStep).'.';
        }

        return null;
    }

    public static function validateStockIntegrity(int|string $stock, int|string $stockReserved): bool
    {
        return QuantityMath::compare($stockReserved, $stock) <= 0;
    }
}
