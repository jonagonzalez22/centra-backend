<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'product_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'quantity' => ['required', 'integer'],
            'type' => ['required', 'string', Rule::in(['input', 'output', 'adjustment'])],
            'concept' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'El producto es obligatorio.',
            'product_id.uuid' => 'El ID del producto debe ser un UUID válido.',
            'product_id.exists' => 'El producto no existe o no pertenece a tu tienda.',
            'quantity.required' => 'La cantidad es obligatoria.',
            'quantity.integer' => 'La cantidad debe ser un número entero.',
            'type.required' => 'El tipo de ajuste es obligatorio.',
            'type.in' => 'El tipo de ajuste debe ser input, output o adjustment.',
            'concept.required' => 'El concepto es obligatorio.',
            'concept.max' => 'El concepto no puede exceder los 255 caracteres.',
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
