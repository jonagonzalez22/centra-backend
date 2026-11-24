<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class PriceListExportDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Omitting pricing_mode preserves the original price-list request contract.
            'pricing_mode' => ['nullable', 'string', Rule::in(['general', 'price_list'])],
            'price_list_id' => ['nullable', 'required_unless:pricing_mode,general', 'prohibited_if:pricing_mode,general', 'uuid'],
            'selection_mode' => ['required', 'string', Rule::in(['filtered', 'selected'])],
            'product_ids' => ['required_if:selection_mode,selected', 'prohibited_if:selection_mode,filtered', 'array', 'min:1', 'max:5000'],
            'product_ids.*' => ['required', 'uuid', 'distinct'],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:255'],
            'filters.category_id' => ['nullable', 'uuid'],
            'filters.brand_id' => ['nullable', 'uuid'],
            'filters.include_out_of_stock' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422));
    }
}
