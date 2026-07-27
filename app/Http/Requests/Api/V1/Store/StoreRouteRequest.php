<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            'driver_id' => ['required', 'uuid', 'exists:users,id'],
            'operational_date' => ['required', 'date', 'after_or_equal:today'],
            'observations' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'El vehículo es obligatorio.',
            'vehicle_id.exists' => 'El vehículo seleccionado no existe.',
            'driver_id.required' => 'El conductor es obligatorio.',
            'driver_id.exists' => 'El conductor seleccionado no existe.',
            'operational_date.required' => 'La fecha operativa es obligatoria.',
            'operational_date.date' => 'La fecha operativa debe ser una fecha válida.',
            'operational_date.after_or_equal' => 'La fecha operativa debe ser hoy o posterior.',
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
