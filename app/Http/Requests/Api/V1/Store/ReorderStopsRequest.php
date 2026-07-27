<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReorderStopsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stop_ids' => ['required', 'array', 'min:1'],
            'stop_ids.*' => ['required', 'uuid', 'exists:route_stops,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'stop_ids.required' => 'Los stops son obligatorios.',
            'stop_ids.array' => 'Los stops deben ser un array.',
            'stop_ids.min' => 'Debe haber al menos un stop.',
            'stop_ids.*.required' => 'Cada stop debe ser especificado.',
            'stop_ids.*.exists' => 'Uno o más stops no existen.',
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
