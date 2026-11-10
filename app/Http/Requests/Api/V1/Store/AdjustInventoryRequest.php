<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Http\Requests\Concerns\NormalizesDecimalQuantities;
use App\Rules\DecimalQuantity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
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
        $storeId = $this->user()->store_id;

        return [
            'product_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'quantity' => ['required', DecimalQuantity::positive()],
            'type' => ['required', 'string', Rule::in(['input', 'output', 'adjustment'])],
            'concept' => ['required', 'string', 'max:255'],
            'unit_cost' => [
                'nullable',
                'string',
                'regex:/^\d+(?:\.\d{1,2})?$/D',
                Rule::prohibitedIf(fn () => $this->input('type') === 'output'),
            ],
            'update_product_cost' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn () => $this->input('type') === 'output'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'El producto es obligatorio.',
            'product_id.uuid' => 'El ID del producto debe ser un UUID válido.',
            'product_id.exists' => 'El producto no existe o no pertenece a tu tienda.',
            'quantity.required' => 'La cantidad es obligatoria.',
            'type.required' => 'El tipo de ajuste es obligatorio.',
            'type.in' => 'El tipo de ajuste debe ser input, output o adjustment.',
            'concept.required' => 'El concepto es obligatorio.',
            'concept.max' => 'El concepto no puede exceder los 255 caracteres.',
            'unit_cost.regex' => 'El precio de costo debe ser un número no negativo con hasta dos decimales.',
            'unit_cost.prohibited' => 'El precio de costo solo puede informarse en entradas de inventario.',
            'update_product_cost.prohibited' => 'La actualización del costo del producto solo aplica a entradas de inventario.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('update_product_cost') && ! $this->filled('unit_cost')) {
                $validator->errors()->add(
                    'unit_cost',
                    'El precio de costo es obligatorio para actualizar el costo del producto.'
                );
            }
        });
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
