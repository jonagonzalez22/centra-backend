<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    $userId = $this->route('user');

    return [
      'name'  => ['sometimes', 'string', 'max:255'],

      'email' => [
        'sometimes',
        'string',
        'email',
        'max:255',
        Rule::unique('users', 'email')->ignore($userId),
      ],

      'password' => ['sometimes', 'string', 'confirmed', 'min:6'],

      'role' => ['sometimes', 'string', 'exists:roles,name'],

      'store_id' => ['sometimes', 'nullable', 'uuid', 'exists:stores,id'],
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
