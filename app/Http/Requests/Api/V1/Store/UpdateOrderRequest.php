<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required_without:requested_delivery_date', 'sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'requested_delivery_date' => ['required_without:items', 'sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'in:customer_requested_reschedule,customer_absent,address_closed,weather_conditions,operational_issue,other'],
            'observation' => ['required_if:reason,other', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required_without' => 'Debe enviar ítems o una nueva fecha de entrega.',
            'items.array' => 'Los productos del pedido deben enviarse como una lista.',
            'items.min' => 'El pedido debe tener al menos un producto.',
            'items.*.product_id.required' => 'El ID del producto es obligatorio.',
            'items.*.product_id.uuid' => 'El ID del producto debe ser un UUID válido.',
            'items.*.product_id.distinct' => 'Un producto no puede repetirse en la edición del pedido.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
            'requested_delivery_date.required_without' => 'Debe enviar ítems o una nueva fecha de entrega.',
            'requested_delivery_date.date_format' => 'La fecha de entrega debe tener formato YYYY-MM-DD.',
            'requested_delivery_date.after_or_equal' => 'La fecha de entrega debe ser hoy o una fecha futura.',
            'observation.required_if' => 'La observación es obligatoria cuando el motivo es "otro".',
            'observation.max' => 'La observación no puede superar los 1000 caracteres.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error', 'message' => 'Error de validación.', 'data' => null, 'errors' => $validator->errors(),
        ], 422));
    }
}
