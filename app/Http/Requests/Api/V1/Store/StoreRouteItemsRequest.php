<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Http\Requests\Concerns\NormalizesDecimalQuantities;
use App\Rules\DecimalQuantity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRouteItemsRequest extends FormRequest
{
    use NormalizesDecimalQuantities;

    protected function prepareForValidation(): void
    {
        $this->normalizeDecimalQuantities(['items.*.quantity_planned']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity_planned' => ['required', DecimalQuantity::positive()],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Los items son obligatorios.',
            'items.*.product_id.required' => 'El ID del producto es obligatorio.',
            'items.*.product_id.exists' => 'Uno o más productos no existen.',
            'items.*.quantity_planned.required' => 'La cantidad planificada es obligatoria.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => 'Error de validación.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
