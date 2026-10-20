<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreSupplierProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('product_ids'))) {
            $this->merge([
                'product_ids' => array_values(array_unique($this->input('product_ids'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'uuid'],
            'mark_as_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
