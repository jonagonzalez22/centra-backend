<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCommercialGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                Rule::unique('commercial_groups')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'json'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del grupo comercial es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede exceder los 255 caracteres.',
            'name.unique' => 'Ya existe un grupo comercial con este nombre en tu tienda.',

            'description.max' => 'La descripción no puede exceder los 1000 caracteres.',
            'settings.json' => 'La configuración debe ser un JSON válido.',
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
