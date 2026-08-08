<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'products.*.quantity_loaded' => ['required', 'integer', 'min:0'],
            'products.*.reason' => ['nullable', 'string', 'max:255'],
            'products.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required' => 'El listado de productos es obligatorio.',
            'products.*.product_id.required' => 'El ID del producto es obligatorio.',
            'products.*.product_id.exists' => 'El producto seleccionado no existe.',
            'products.*.quantity_loaded.required' => 'La cantidad cargada es obligatoria.',
            'products.*.quantity_loaded.integer' => 'La cantidad cargada debe ser un número entero.',
            'products.*.quantity_loaded.min' => 'La cantidad cargada no puede ser negativa.',
            'products.*.reason.max' => 'El motivo no puede superar los 255 caracteres.',
            'products.*.notes.max' => 'Las notas no pueden superar los 1000 caracteres.',
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
