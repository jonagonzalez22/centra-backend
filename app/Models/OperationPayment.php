<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationPayment extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'operation_id',
        'store_payment_method_id',
        'amount',
        'reference',
        'payment_details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_details' => 'array',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CommercialOperation::class);
    }

    public function storePaymentMethod(): BelongsTo
    {
        return $this->belongsTo(StorePaymentMethod::class);
    }
}
