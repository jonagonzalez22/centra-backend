<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Http\Requests\Concerns\NormalizesDecimalQuantities;
use App\Rules\DecimalQuantity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemQuantityRequest extends FormRequest
{
    use NormalizesDecimalQuantities;

    protected function prepareForValidation(): void
    {
        $this->normalizeDecimalQuantities(['quantity']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quantity' => ['required', DecimalQuantity::positive()]];
    }
}
