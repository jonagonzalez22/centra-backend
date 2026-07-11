<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $paymentMethodId = $this->route('payment_method');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('payment_methods', 'code')->ignore($paymentMethodId), 'regex:/^[a-z][a-z0-9_]*$/'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',

            'code.unique' => 'Ya existe un método de pago con este código.',
            'code.max' => 'El código no puede tener más de 50 caracteres.',
            'code.regex' => 'El código debe estar en formato slug (ej: cash, credit_card).',

            'icon.max' => 'El ícono no puede tener más de 100 caracteres.',
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
