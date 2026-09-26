<?php

namespace App\Http\Requests\Api\V1\Driver;

use App\Http\Requests\Concerns\NormalizesDecimalQuantities;
use App\Rules\DecimalQuantity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CollectionPreviewRequest extends FormRequest
{
    use NormalizesDecimalQuantities;

    protected function prepareForValidation(): void
    {
        $this->normalizeDecimalQuantities(['items.*.quantity_delivered']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.route_stop_item_id' => ['required', 'uuid', 'distinct', 'exists:route_stop_items,id'],
            'items.*.quantity_delivered' => ['required', DecimalQuantity::nonNegative()],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422));
    }
}
