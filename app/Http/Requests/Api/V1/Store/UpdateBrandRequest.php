<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
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

        return ['name' => ['sometimes', 'string', 'max:100', Rule::unique('brands')->where(fn ($q) => $q->where('store_id', $storeId))->ignore($this->route('brand'))], 'is_active' => ['sometimes', 'boolean']];
    }
}
