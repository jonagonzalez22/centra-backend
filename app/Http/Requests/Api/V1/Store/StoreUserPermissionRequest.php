<?php

namespace App\Http\Requests\Api\V1\Store;

use App\Support\PermissionFeatureResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $permissions = $this->input('permissions', []);

            if (! is_array($permissions)) {
                return;
            }

            $authUser = $this->user();
            $store = $authUser->store->load('plan.features');

            foreach ($permissions as $permission) {
                $featureCode = PermissionFeatureResolver::resolveFeature($permission);

                if ($featureCode === null) {
                    $validator->errors()->add(
                        'permissions',
                        "El permiso '{$permission}' no puede ser asignado por un administrador de tienda."
                    );

                    continue;
                }

                if (! $store->hasFeature($featureCode)) {
                    $validator->errors()->add(
                        'permissions',
                        "El permiso '{$permission}' requiere la funcionalidad '{$featureCode}' que no está disponible en tu plan."
                    );
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Error de validación.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422));
    }
}
