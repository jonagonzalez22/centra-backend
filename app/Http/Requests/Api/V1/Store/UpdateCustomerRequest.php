<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Models\Customer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['document_type_id', 'document_number'] as $field) {
            if (array_key_exists($field, $this->all())) {
                $value = $this->input($field);
                $this->merge([$field => is_string($value) && trim($value) === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;
        $customerId = $this->route('customer');

        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'document_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                'exists:document_types,id',
            ],
            'document_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($storeId, $customerId) {
                    $normalized = preg_replace('/[^0-9]/', '', $value);
                    $exists = Customer::forStore($storeId)
                        ->where('document_number_normalized', $normalized)
                        ->where('id', '!=', $customerId)
                        ->exists();
                    if ($exists) {
                        $fail('Ya existe un cliente con este número de documento en tu tienda.');
                    }
                },
            ],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'El nombre de visualización es obligatorio.',
            'display_name.max' => 'El nombre de visualización no puede exceder los 255 caracteres.',
            'first_name.max' => 'El nombre no puede exceder los 100 caracteres.',
            'last_name.max' => 'El apellido no puede exceder los 100 caracteres.',
            'company_name.max' => 'El nombre de la empresa no puede exceder los 255 caracteres.',
            'document_type_id.uuid' => 'El ID del tipo de documento debe ser un UUID válido.',
            'document_type_id.exists' => 'El tipo de documento no existe.',
            'document_number.max' => 'El número de documento no puede exceder los 50 caracteres.',
            'status.in' => 'El estado debe ser activo o inactivo.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $customer = Customer::forStore($this->user()->store_id)->find($this->route('customer'));
            if (! $customer) {
                return;
            }

            $type = array_key_exists('document_type_id', $this->all()) ? $this->input('document_type_id') : $customer->document_type_id;
            $number = array_key_exists('document_number', $this->all()) ? $this->input('document_number') : $customer->document_number;

            if (($type === null) !== ($number === null)) {
                $message = 'Completá el tipo y número de documento, o dejá ambos vacíos.';
                $validator->errors()->add('document_type_id', $message);
                $validator->errors()->add('document_number', $message);
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => 'Error de validación.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
