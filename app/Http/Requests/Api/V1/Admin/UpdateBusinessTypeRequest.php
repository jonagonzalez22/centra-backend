<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateBusinessTypeRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    $businessTypeId = $this->route('business_type');

    return [
      'name'        => ['sometimes', 'string', 'max:255', Rule::unique('business_types', 'name')->ignore($businessTypeId)],
      'description' => ['nullable', 'string', 'max:500'],
      'status'      => ['sometimes', 'string', 'in:active,inactive'],
    ];
  }

  public function messages(): array
  {
    return [
      'name.unique' => 'Ya existe un tipo de negocio con este nombre.',
      'name.max'    => 'El nombre no puede tener más de 255 caracteres.',

      'description.max' => 'La descripción no puede tener más de 500 caracteres.',

      'status.in' => 'El estado debe ser activo o inactivo.',
    ];
  }

  protected function failedValidation(Validator $validator): void
  {
    throw new HttpResponseException(
      response()->json([
        'status'  => 'error',
        'message' => 'Error de validación.',
        'data'    => null,
        'errors'  => $validator->errors(),
      ], 422)
    );
  }
}
