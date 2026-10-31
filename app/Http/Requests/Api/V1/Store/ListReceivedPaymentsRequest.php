<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListReceivedPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'date_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'operation_number' => ['nullable', 'string', 'max:50'],
            'customer' => ['nullable', 'string', 'max:255'],
            'store_payment_method_id' => [
                'nullable',
                'uuid',
                Rule::exists('store_payment_methods', 'id')->where(fn ($query) => $query->where('store_id', $storeId)),
            ],
            'origin' => ['nullable', 'string', Rule::in(['pos_sale', 'order_deposit', 'manual_collection', 'route_collection', 'unknown'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'reversed'])],
            // Do not require the collector to still belong to this store: historical
            // payments remain filterable after a user is deactivated or transferred.
            'collected_by' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
