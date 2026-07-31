<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListCommercialOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'type' => ['nullable', 'string', Rule::in(['sale', 'order'])],
            'status' => ['nullable', 'string', Rule::in(['open', 'confirmed', 'cancelled', 'closed', 'delivered', 'partially_delivered'])],
            'customer_id' => [
                'nullable',
                'uuid',
                Rule::exists('customers', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de operación debe ser: sale u order.',
            'status.in' => 'El estado debe ser: open, confirmed, cancelled, closed, delivered o partially_delivered.',
            'customer_id.uuid' => 'El ID del cliente debe ser un UUID válido.',
            'customer_id.exists' => 'El cliente no existe o no pertenece a tu tienda.',
            'date_from.date' => 'La fecha de inicio debe ser una fecha válida.',
            'date_to.date' => 'La fecha de fin debe ser una fecha válida.',
            'date_to.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
            'per_page.integer' => 'La cantidad de items por página debe ser un número entero.',
            'per_page.min' => 'La cantidad de items por página debe ser al menos 1.',
            'per_page.max' => 'La cantidad de items por página no puede exceder 100.',
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
