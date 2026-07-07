<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LocationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address' => ['nullable', 'string', 'max:1000'],
            'input' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasAddress = $this->filled('address');
            $hasInput = $this->filled('input');

            if (! $hasAddress && ! $hasInput) {
                $validator->errors()->add('input', 'Se requiere una dirección, input o link de Google Maps.');
            }

            if ($hasAddress && $hasInput) {
                $validator->errors()->add('input', 'Solo se debe proporcionar address o input, no ambos.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'address.required' => 'La dirección es requerida.',
            'address.string' => 'La dirección debe ser una cadena de texto.',
            'address.max' => 'La dirección no puede superar los 1000 caracteres.',
            'input.required' => 'El input es requerido.',
            'input.string' => 'El input debe ser una cadena de texto.',
            'input.max' => 'El input no puede superar los 1000 caracteres.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422));
    }

    public function getLocationInput(): string
    {
        return $this->input('address') ?? $this->input('input') ?? '';
    }
}
