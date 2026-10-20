<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['supplier_code', 'supplier_product_name'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['prohibited'],
            'product_id' => [
                'required',
                'uuid',
                Rule::unique('supplier_products')->where(fn ($query) => $query->where('supplier_id', $this->route('supplier'))),
            ],
            'supplier_code' => ['nullable', 'string', 'max:100'],
            'supplier_product_name' => ['nullable', 'string', 'max:255'],
            'is_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
