<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('notes')) {
            $notes = trim((string) $this->input('notes'));
            $this->merge(['notes' => $notes === '' ? null : $notes]);
        }
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'],
            'purchase_type' => ['required', 'in:order,direct'],
            'notes' => ['nullable', 'string'],
            'status' => ['prohibited'],
            'total' => ['prohibited'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/D'],
            'items.*.unit_price' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'items.*.quantity_received' => ['prohibited'],
        ];
    }
}
