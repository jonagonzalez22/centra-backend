<?php

namespace App\Http\Requests\Api\V1\Driver;

use App\Http\Requests\Concerns\NormalizesDecimalQuantities;
use App\Rules\DecimalQuantity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AddExtraSaleRequest extends FormRequest
{
    use NormalizesDecimalQuantities;

    protected function prepareForValidation(): void
    {
        $this->normalizeDecimalQuantities(['items.*.quantity']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', DecimalQuantity::positive()],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Los items son obligatorios.',
            'items.array' => 'Los items deben ser un arreglo.',
            'items.min' => 'Debe haber al menos un item.',
            'items.*.product_id.required' => 'El ID del producto es obligatorio.',
            'items.*.product_id.uuid' => 'El ID del producto debe ser un UUID válido.',
            'items.*.product_id.exists' => 'El producto no existe.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
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
