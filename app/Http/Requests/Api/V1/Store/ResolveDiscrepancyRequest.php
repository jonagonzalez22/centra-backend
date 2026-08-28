<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResolveDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_stop_item_id' => ['required', 'uuid', 'exists:route_stop_items,id'],
            'resolution_type' => ['required', 'string', 'in:returned,pending_redelivery,missing,damaged,rejected_by_customer,extra_sale,other'],
            'quantity_to_resolve' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'route_stop_item_id.required' => 'El item de parada es obligatorio.',
            'route_stop_item_id.exists' => 'El item de parada no existe.',
            'resolution_type.required' => 'El tipo de resolución es obligatorio.',
            'resolution_type.in' => 'El tipo de resolución no es válido.',
            'quantity_to_resolve.required' => 'La cantidad a resolver es obligatoria.',
            'quantity_to_resolve.min' => 'La cantidad a resolver debe ser al menos 1.',
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
