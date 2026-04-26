<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    $validateCuit = function (string $attribute, mixed $value, \Closure $fail): void {

      $value = (string) $value;

      if (!preg_match('/^\d{11}$/', $value)) {
        $fail('El CUIT debe contener exactamente 11 dígitos numéricos.');
        return;
      }

      $digits = array_map('intval', str_split($value));

      $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

      $sum = 0;

      for ($i = 0; $i < 10; $i++) {
        $sum += $digits[$i] * $weights[$i];
      }

      $rest = $sum % 11;
      $dv = 11 - $rest;

      if ($dv == 11) {
        $dv = 0;
      } elseif ($dv == 10) {
        $dv = 9;
      }

      if ($digits[10] !== $dv) {
        $fail('El CUIT ingresado no es válido.');
      }
    };

    return [
      'name' => ['required', 'string', 'max:255'],

      'business_type_id' => ['required', 'integer', 'exists:business_types,id'],

      'cuit' => [
        'required',
        'string',
        'regex:/^\d{11}$/',
        $validateCuit,
      ],

      'address' => ['required', 'string', 'max:255'],

      'state' => ['required', 'string', 'max:255'],

      'city' => ['required', 'string', 'max:255'],

      'country' => ['required', 'string', 'max:255'],

      'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],

      'email' => [
        'required',
        'string',
        'email',
        'max:255',
        Rule::unique('stores', 'email')->ignore($this->route('id') ?? $this->route('store'))
      ],
      'is_active' => ['nullable', 'boolean'],
      'inactive_reason' => ['nullable', 'string', 'max:255'],
      'inactive_at' => ['nullable', 'date'],
      'url_logo' => ['nullable', 'string', 'url', 'max:255'],
    ];
  }

  public function messages(): array
  {
    return [
      'name.required' => 'El nombre de la tienda es obligatorio.',

      'business_type_id.required' => 'El tipo de negocio es obligatorio.',
      'business_type_id.exists' => 'El tipo de negocio seleccionado no existe.',

      'cuit.required' => 'El CUIT es obligatorio.',
      'cuit.regex' => 'El CUIT debe contener exactamente 11 dígitos numéricos.',

      'address.required' => 'La dirección es obligatoria.',

      'state.required' => 'La provincia/estado es obligatorio.',

      'city.required' => 'La ciudad es obligatoria.',

      'country.required' => 'El país es obligatorio.',

      'email.required' => 'El email es obligatorio.',
      'email.email' => 'El email debe tener un formato válido.',
      'email.unique' => 'Ya existe una tienda registrada con este email.',

      'phone.required' => 'El teléfono es obligatorio.',
      'phone.regex' => 'El teléfono debe tener formato internacional válido, por ejemplo: +541112345678.',

      'url_logo.url' => 'La URL del logo debe ser válida.',
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
