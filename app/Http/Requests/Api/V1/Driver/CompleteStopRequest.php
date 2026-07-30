<?php

namespace App\Http\Requests\Api\V1\Driver;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CompleteStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:completed,failed'],
            'gps_lat' => ['nullable', 'numeric'],
            'gps_lon' => ['nullable', 'numeric'],
            'rejection_reason_id' => [
                'required_if:status,failed',
                'uuid',
                'exists:delivery_rejection_reasons,id',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.route_stop_item_id' => [
                'required',
                'uuid',
                'exists:route_stop_items,id',
            ],
            'items.*.quantity_delivered' => [
                'required',
                'integer',
                'min:0',
            ],
            'items.*.rejection_reason_id' => [
                'nullable',
                'uuid',
                'exists:delivery_rejection_reasons,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de la entrega es obligatorio.',
            'status.in' => 'El estado debe ser completed o failed.',
            'rejection_reason_id.required_if' => 'El motivo de rechazo es obligatorio cuando la entrega falla.',
            'rejection_reason_id.exists' => 'El motivo de rechazo no es válido.',
            'items.required' => 'Los items son obligatorios.',
            'items.*.route_stop_item_id.required' => 'El ID del item es obligatorio.',
            'items.*.route_stop_item_id.exists' => 'Uno o más items no existen.',
            'items.*.quantity_delivered.required' => 'La cantidad entregada es obligatoria.',
            'items.*.quantity_delivered.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity_delivered.min' => 'La cantidad entregada no puede ser negativa.',
            'items.*.rejection_reason_id.exists' => 'El motivo de rechazo no es válido.',
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
