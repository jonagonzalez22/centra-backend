<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ToggleVehicleActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'inactivation_reason' => [
                'required_if:is_active,false',
                'nullable',
                'string',
                Rule::in(config('vehicle_catalogs.inactivation_reasons')),
            ],
            'inactivation_notes' => [
                'required_if:inactivation_reason,other',
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.required' => 'El campo is_active es obligatorio.',
            'is_active.boolean' => 'El campo is_active debe ser verdadero o falso.',
            'inactivation_reason.required_if' => 'El motivo de inactivación es obligatorio al desactivar un vehículo.',
            'inactivation_reason.in' => 'El motivo de inactivación seleccionado no es válido.',
            'inactivation_notes.required_if' => 'Las notas de inactivación son obligatorias cuando el motivo es "other".',
            'inactivation_notes.max' => 'Las notas no pueden exceder los 1000 caracteres.',
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
