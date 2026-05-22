<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SyncFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'features' => ['required', 'array'],
            'features.*.feature_id' => ['required', 'string', 'exists:features,id'],
            'features.*.limit_value' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'features.required' => 'El array de funcionalidades es obligatorio.',
            'features.array' => 'El formato de funcionalidades no es válido.',

            'features.*.feature_id.required' => 'El ID de la funcionalidad es obligatorio.',
            'features.*.feature_id.exists' => 'La funcionalidad seleccionada no existe.',

            'features.*.limit_value.integer' => 'El valor límite debe ser un número entero.',
            'features.*.limit_value.min' => 'El valor límite no puede ser negativo.',
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
