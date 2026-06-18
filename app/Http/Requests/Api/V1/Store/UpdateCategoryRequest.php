<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category');
        $storeId = $this->user()->store_id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('categories')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    })
                    ->ignore($categoryId),
            ],

            'description' => ['nullable', 'string', 'max:500'],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'El nombre no puede exceder los 100 caracteres.',
            'name.unique' => 'Ya existe una categoría con este nombre en tu tienda.',

            'description.max' => 'La descripción no puede exceder los 500 caracteres.',
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
