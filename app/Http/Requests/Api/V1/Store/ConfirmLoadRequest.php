<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConfirmLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.route_stop_item_id' => ['required', 'uuid', 'exists:route_stop_items,id'],
            'items.*.quantity_loaded' => ['required', 'integer', 'min:0'],
            'items.*.reason' => ['nullable', 'string'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Los items son obligatorios.',
            'items.*.route_stop_item_id.required' => 'El ID del item es obligatorio.',
            'items.*.route_stop_item_id.exists' => 'Uno o más items no existen.',
            'items.*.quantity_loaded.required' => 'La cantidad cargada es obligatoria.',
            'items.*.quantity_loaded.min' => 'La cantidad cargada no puede ser negativa.',
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
