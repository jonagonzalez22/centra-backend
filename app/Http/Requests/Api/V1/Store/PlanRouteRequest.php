<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlanRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'departure_time' => ['nullable', 'string', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'departure_time.required' => 'El horario de salida es obligatorio.',
            'departure_time.date_format' => 'El horario de salida debe tener el formato H:i.',
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
