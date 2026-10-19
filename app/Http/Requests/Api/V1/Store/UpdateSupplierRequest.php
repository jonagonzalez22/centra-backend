<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'business_name' => ['sometimes', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:100', Rule::unique('suppliers')->where(fn ($query) => $query->where('store_id', $storeId))->ignore($this->route('supplier'))],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
