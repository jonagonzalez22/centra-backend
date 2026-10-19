<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierProductRequest extends FormRequest
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
            'product_id' => ['prohibited'],
            'supplier_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier_product_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
