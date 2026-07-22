<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleDeliveryDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'in:customer_requested_reschedule,customer_absent,address_closed,weather_conditions,operational_issue,other'],
            'observation' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_date.required' => 'La nueva fecha es obligatoria.',
            'new_date.date' => 'La nueva fecha debe ser una fecha válida.',
            'new_date.after_or_equal' => 'La nueva fecha debe ser hoy o una fecha futura.',
            'reason.required' => 'El motivo es obligatorio.',
            'reason.in' => 'El motivo seleccionado no es válido.',
            'observation.required_if' => 'La observación es obligatoria cuando el motivo es "otro".',
            'observation.max' => 'La observación no puede superar los 1000 caracteres.',
        ];
    }
}
