<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateRouteRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'vehicle_id' => ['sometimes', 'uuid', 'exists:vehicles,id'],
      'driver_id' => ['sometimes', 'uuid', 'exists:users,id'],
      'operational_date' => ['sometimes', 'date', 'after_or_equal:today'],
      'departure_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i,H:i:s'],
      'observations' => ['nullable', 'string'],
    ];
  }

  public function messages(): array
  {
    return [
      'vehicle_id.exists' => 'El vehículo seleccionado no existe.',
      'driver_id.exists' => 'El conductor seleccionado no existe.',
      'operational_date.date' => 'La fecha operativa debe ser una fecha válida.',
      'operational_date.after_or_equal' => 'La fecha operativa debe ser hoy o posterior.',
      'departure_time.date_format' => 'La hora de salida debe tener el formato HH:MM.',
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
