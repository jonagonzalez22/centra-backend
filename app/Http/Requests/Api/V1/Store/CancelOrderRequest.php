<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'in:customer_cancelled,payment_failed,out_of_stock,pricing_error,duplicate_order,other'],
            'reason_note' => ['required_if:reason_code,other', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'El motivo de cancelación es obligatorio.',
            'reason_code.in' => 'El motivo seleccionado no es válido.',
            'reason_note.required_if' => 'La nota es obligatoria cuando el motivo es "otro".',
            'reason_note.max' => 'La nota no puede superar los 1000 caracteres.',
        ];
    }
}
