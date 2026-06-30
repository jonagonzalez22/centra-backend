<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Models\Customer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'display_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'document_type_id' => [
                'required',
                'uuid',
                'exists:document_types,id',
            ],
            'document_number' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($storeId) {
                    $normalized = preg_replace('/[^0-9]/', '', $value);
                    $exists = Customer::forStore($storeId)
                        ->where('document_number_normalized', $normalized)
                        ->exists();
                    if ($exists) {
                        $fail('Ya existe un cliente con este número de documento en tu tienda.');
                    }
                },
            ],
            'commercial_group_id' => [
                'nullable',
                'uuid',
                Rule::exists('commercial_groups', 'id')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }),
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
            'document_type_id.required' => 'El tipo de documento es obligatorio.',
            'document_type_id.uuid' => 'El ID del tipo de documento debe ser un UUID válido.',
            'document_type_id.exists' => 'El tipo de documento no existe.',
            'document_number.required' => 'El número de documento es obligatorio.',
            'document_number.max' => 'El número de documento no puede exceder los 50 caracteres.',
            'commercial_group_id.uuid' => 'El ID del grupo comercial debe ser un UUID válido.',
            'commercial_group_id.exists' => 'El grupo comercial no existe o no pertenece a tu tienda.',
            'status.in' => 'El estado debe ser activo o inactivo.',
        ];
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
