<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'category_id' => [
                'required',
                'uuid',
                Rule::exists('categories', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'stock_reserved' => ['nullable', 'integer', 'min:0'],
            'stock_min' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.max' => 'El nombre no puede exceder los 255 caracteres.',
            'sku.required' => 'El código SKU es obligatorio.',
            'sku.max' => 'El código SKU no puede exceder los 100 caracteres.',
            'sku.unique' => 'Ya existe un producto con este código SKU en tu tienda.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.uuid' => 'El ID de la categoría debe ser un UUID válido.',
            'category_id.exists' => 'La categoría no existe o no pertenece a tu tienda.',
            'barcode.max' => 'El código de barras no puede exceder los 100 caracteres.',
            'price.required' => 'El precio es obligatorio.',
            'price.numeric' => 'El precio debe ser un número válido.',
            'price.min' => 'El precio no puede ser negativo.',
            'cost.numeric' => 'El costo debe ser un número válido.',
            'cost.min' => 'El costo no puede ser negativo.',
            'stock.integer' => 'El stock debe ser un número entero.',
            'stock.min' => 'El stock no puede ser negativo.',
            'stock_reserved.integer' => 'El stock reservado debe ser un número entero.',
            'stock_reserved.min' => 'El stock reservado no puede ser negativo.',
            'stock_min.integer' => 'El stock mínimo debe ser un número entero.',
            'stock_min.min' => 'El stock mínimo no puede ser negativo.',
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
