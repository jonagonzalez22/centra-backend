<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCustomerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'customer_id' => [
                'required', 'uuid',
                Rule::exists('customers', 'id')->where(fn ($q) => $q->where('store_id', $storeId)),
            ],
            'locality_id' => ['required', 'uuid', 'exists:geography_localities,id'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:10'],
            'apartment' => ['nullable', 'string', 'max:10'],
            'postal_code' => ['required', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'type' => ['required', 'string', Rule::in(['billing', 'delivery', 'other'])],
            'is_main' => ['nullable', 'boolean'],
            'observations' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'El cliente es obligatorio.',
            'customer_id.exists' => 'El cliente no existe o no pertenece a tu tienda.',
            'locality_id.required' => 'La localidad es obligatoria.',
            'locality_id.exists' => 'La localidad seleccionada no existe.',
            'street.required' => 'La calle es obligatoria.',
            'number.required' => 'El número es obligatorio.',
            'postal_code.required' => 'El código postal es obligatorio.',
            'type.required' => 'El tipo de dirección es obligatorio.',
            'type.in' => 'El tipo debe ser billing, delivery u other.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
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
