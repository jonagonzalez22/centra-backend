<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReverseOperationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => [
                'required',
                'string',
                Rule::in([
                    'payment_error',
                    'duplicate_payment',
                    'wrong_amount',
                    'wrong_payment_method',
                    'other',
                ]),
            ],
            'reason_note' => ['required_if:reason_code,other', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'El motivo de anulación es obligatorio.',
            'reason_code.in' => 'El motivo seleccionado no es válido.',
            'reason_note.required_if' => 'El detalle es obligatorio cuando el motivo es "otro".',
            'reason_note.max' => 'El detalle no puede superar los 1000 caracteres.',
        ];
    }
}
