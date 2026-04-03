<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'email'    => ['required', 'string', 'email'],
      'password' => ['required', 'string', 'min:8'],
    ];
  }

  public function messages(): array
  {
    return [
      'email.required'    => 'El campo email es obligatorio.',
      'email.email'       => 'El email debe tener un formato válido.',
      'password.required' => 'El campo contraseña es obligatorio.',
      'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
    ];
  }

  protected function failedValidation(Validator $validator): void
  {
    throw new HttpResponseException(
      response()->json([
        'status' => 'error',
        'message' => 'Error de validación.',
        'data'    => null,
        'errors'  => $validator->errors(),
      ], 422)
    );
  }
}
