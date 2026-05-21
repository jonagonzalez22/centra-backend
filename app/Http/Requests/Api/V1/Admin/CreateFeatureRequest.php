<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:features,code', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código es obligatorio.',
            'code.unique' => 'Ya existe una funcionalidad con este código.',
            'code.max' => 'El código no puede tener más de 255 caracteres.',
            'code.regex' => 'El código debe estar en formato snake_case (ej: pos, multi_user).',

            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',

            'description.max' => 'La descripción no puede tener más de 500 caracteres.',
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
