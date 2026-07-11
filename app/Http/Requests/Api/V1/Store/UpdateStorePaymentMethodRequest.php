<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateStorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['boolean'],
            'custom_name' => ['nullable', 'string', 'max:255'],
            'requires_reference' => ['boolean'],
            'account_details' => ['nullable', 'array'],
            'account_details.bank' => ['nullable', 'string', 'max:255'],
            'account_details.account_number' => ['nullable', 'string', 'max:100'],
            'account_details.alias' => ['nullable', 'string', 'max:255'],
            'account_details.cuit_rut' => ['nullable', 'string', 'max:50'],
            'account_details.holder_name' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'custom_name.max' => 'El nombre personalizado no puede tener más de 255 caracteres.',
            'account_details.array' => 'Los datos de cuenta deben ser un objeto JSON.',
            'account_details.bank.max' => 'El nombre del banco no puede tener más de 255 caracteres.',
            'account_details.account_number.max' => 'El número de cuenta no puede tener más de 100 caracteres.',
            'account_details.alias.max' => 'El alias no puede tener más de 255 caracteres.',
            'account_details.cuit_rut.max' => 'El CUIT/RUT no puede tener más de 50 caracteres.',
            'account_details.holder_name.max' => 'El nombre del titular no puede tener más de 255 caracteres.',
            'sort_order.integer' => 'El orden debe ser un número entero.',
            'sort_order.min' => 'El orden no puede ser negativo.',
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
