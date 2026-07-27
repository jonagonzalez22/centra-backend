<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->user()->store_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'plate' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vehicles')
                    ->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
            ],
            'type' => ['required', 'string', Rule::in(config('vehicle_catalogs.types'))],
            'capacity_kg' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del vehículo es obligatorio.',
            'plate.required' => 'La patente es obligatoria.',
            'plate.unique' => 'Ya existe un vehículo con esta patente en tu tienda.',
            'type.required' => 'El tipo de vehículo es obligatorio.',
            'type.in' => 'El tipo de vehículo seleccionado no es válido.',
            'capacity_kg.integer' => 'La capacidad debe ser un número entero.',
            'capacity_kg.min' => 'La capacidad debe ser al menos 1 kg.',
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
