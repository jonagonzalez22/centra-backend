<?php

namespace App\Http\Requests\Api\V1\Driver;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CompleteStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()?->store_id;
        $validRejectionReason = Rule::exists('delivery_rejection_reasons', 'id')
            ->where(function ($query) use ($storeId) {
                $query->where('is_active', true)
                    ->where(function ($query) use ($storeId) {
                        $query->whereNull('store_id')
                            ->orWhere('store_id', $storeId);
                    });
            });

        return [
            'status' => ['required', 'string', 'in:completed,failed'],
            'gps_lat' => ['nullable', 'numeric'],
            'gps_lon' => ['nullable', 'numeric'],
            'signature_uri' => ['nullable', 'string', 'max:500'],
            'evidence_uris' => ['nullable', 'array'],
            'evidence_uris.*' => ['string'],
            'rejection_reason_id' => [
                'required_if:status,failed',
                'uuid',
                $validRejectionReason,
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.route_stop_item_id' => [
                'required',
                'uuid',
                'distinct',
                'exists:route_stop_items,id',
            ],
            'items.*.quantity_delivered' => [
                'required',
                'integer',
                'min:0',
            ],
            'items.*.quantity_released_for_extra_sale' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'items.*.rejection_reason_id' => [
                'nullable',
                'uuid',
                $validRejectionReason,
            ],
            'payments' => ['nullable', 'array'],
            'payments.*.store_payment_method_id' => [
                'required',
                'uuid',
                'exists:store_payment_methods,id',
            ],
            'payments.*.amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'payments.*.reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payments.*.notes' => [
                'nullable',
                'string',
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
            'items.*.quantity_released_for_extra_sale.integer' => 'La cantidad liberada para Venta Extra debe ser un número entero.',
            'items.*.quantity_released_for_extra_sale.min' => 'La cantidad liberada para Venta Extra no puede ser negativa.',
            'items.*.rejection_reason_id.exists' => 'El motivo de rechazo no es válido.',
            'payments.*.store_payment_method_id.required' => 'El método de pago es obligatorio.',
            'payments.*.store_payment_method_id.exists' => 'El método de pago no es válido.',
            'payments.*.amount.required' => 'El monto del pago es obligatorio.',
            'payments.*.amount.numeric' => 'El monto debe ser un número.',
            'payments.*.amount.min' => 'El monto debe ser mayor a 0.',
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
