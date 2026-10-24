<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Models\PurchaseDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'number' => trim((string) $this->input('number', '')),
            'notes' => $this->has('notes') && trim((string) $this->input('notes')) !== ''
                ? trim((string) $this->input('notes'))
                : null,
            'total_amount' => $this->input('total_amount') === '' ? null : $this->input('total_amount'),
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['prohibited'],
            'type' => ['required', 'string', Rule::in(PurchaseDocument::TYPES)],
            'number' => ['required', 'string', 'max:100'],
            'issued_at' => ['required', 'date_format:Y-m-d'],
            'total_amount' => [
                'required_if:type,'.implode(',', PurchaseDocument::AMOUNT_REQUIRED_TYPES),
                'nullable',
                'numeric',
                'min:0.01',
                'regex:/^\\d+(?:\\.\\d{1,2})?$/D',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'created_by' => ['prohibited'],
            'purchase_id' => ['prohibited'],
        ];
    }
}
