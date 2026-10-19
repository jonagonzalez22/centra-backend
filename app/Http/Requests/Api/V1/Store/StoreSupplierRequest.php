<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['business_name', 'tax_id', 'contact_name', 'phone', 'email', 'address'] as $f) {
            if ($this->has($f)) {
                $value = trim((string) $this->input($f));
                $this->merge([$f => $value === '' && $f !== 'business_name' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        $store = $this->user()->store_id;

        return ['business_name' => ['required', 'string', 'max:150'], 'tax_id' => ['nullable', 'string', 'max:100', Rule::unique('suppliers')->where(fn ($q) => $q->where('store_id', $store))], 'contact_name' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean']];
    }
}
