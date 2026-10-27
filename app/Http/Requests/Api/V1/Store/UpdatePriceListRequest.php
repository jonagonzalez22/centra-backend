<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('price_lists')->where(fn ($query) => $query->where('store_id', $storeId))->ignore($this->route('price_list'))],
            'discount_percentage' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
