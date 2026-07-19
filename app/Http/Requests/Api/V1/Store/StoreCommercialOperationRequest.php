<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCommercialOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'type' => ['required', 'string', Rule::in(['sale', 'order'])],
            'customer_id' => [
                'nullable',
                'uuid',
                Rule::exists('customers', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'requested_delivery_date' => [
                'prohibited_if:type,sale',
                'nullable',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.store_payment_method_id' => [
                'required',
                'uuid',
                Rule::exists('store_payment_methods', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'El tipo de operación es obligatorio.',
            'type.in' => 'El tipo de operación debe ser: sale u order.',
            'customer_id.uuid' => 'El ID del cliente debe ser un UUID válido.',
            'customer_id.exists' => 'El cliente no existe o no pertenece a tu tienda.',
            'requested_delivery_date.prohibited_if' => 'La fecha de entrega solicitada no aplica para ventas.',
            'requested_delivery_date.date' => 'La fecha de entrega debe ser una fecha válida.',
            'requested_delivery_date.after_or_equal' => 'La fecha de entrega debe ser igual o posterior a hoy.',
            'items.required' => 'La operación debe tener al menos un producto.',
            'items.*.product_id.required' => 'El ID del producto es obligatorio.',
            'items.*.product_id.exists' => 'El producto no existe o no pertenece a tu tienda.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
            'items.*.price.required' => 'El precio del producto es obligatorio.',
            'items.*.price.numeric' => 'El precio debe ser un número válido.',
            'items.*.price.min' => 'El precio no puede ser negativo.',
            'payments.*.store_payment_method_id.required' => 'El medio de pago es obligatorio.',
            'payments.*.store_payment_method_id.exists' => 'El medio de pago no existe o no está configurado para tu tienda.',
            'payments.*.amount.required' => 'El monto del pago es obligatorio.',
            'payments.*.amount.numeric' => 'El monto del pago debe ser un número válido.',
            'payments.*.amount.min' => 'El monto del pago debe ser mayor a cero.',
            'payments.*.reference.max' => 'La referencia no puede exceder los 255 caracteres.',
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
