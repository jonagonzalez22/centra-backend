<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', Rule::in(['open', 'confirmed', 'cancelled', 'closed'])],
            'operation_number' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'locality' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.date_format' => 'La fecha debe tener el formato YYYY-MM-DD.',
            'date_from.date_format' => 'La fecha de inicio debe tener el formato YYYY-MM-DD.',
            'date_to.date_format' => 'La fecha de fin debe tener el formato YYYY-MM-DD.',
            'status.in' => 'El estado debe ser: open, confirmed, cancelled o closed.',
            'operation_number.max' => 'El número de operación no puede exceder 20 caracteres.',
            'customer_name.max' => 'El nombre del cliente no puede exceder 255 caracteres.',
            'locality.max' => 'La localidad no puede exceder 255 caracteres.',
            'per_page.integer' => 'La cantidad de items por página debe ser un número entero.',
            'per_page.min' => 'La cantidad de items por página debe ser al menos 1.',
            'per_page.max' => 'La cantidad de items por página no puede exceder 100.',
            'page.integer' => 'El número de página debe ser un número entero.',
            'page.min' => 'El número de página debe ser al menos 1.',
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
