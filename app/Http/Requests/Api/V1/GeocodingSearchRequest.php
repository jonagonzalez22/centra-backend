<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Location\Resolvers\AppleMapsLinkResolver;
use App\Services\Location\Resolvers\GoogleMapsLinkResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GeocodingSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address' => ['nullable', 'string', 'max:1000'],
            'input' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasAddress = $this->filled('address');
            $hasInput = $this->filled('input');

            if (! $hasAddress && ! $hasInput) {
                $validator->errors()->add('input', 'Se requiere una dirección, coordenadas o enlace de mapas compatible.');
            }

            if ($hasAddress && $hasInput) {
                $validator->errors()->add('input', 'Solo se debe proporcionar address o input, no ambos.');
            }

            $value = $this->getLocationInput();
            if (($hasAddress || $hasInput) && trim($value) === '') {
                $validator->errors()->add($hasAddress ? 'address' : 'input', 'La ubicación no puede estar vacía.');
            }

            // URLs are never treated as free-form addresses. This keeps the
            // URL resolvers as the only code path that can make a request to a
            // URL supplied by a user.
            if ($this->looksLikeUrl($value) && ! $this->isSupportedMapsUrl($value)) {
                $validator->errors()->add($hasAddress ? 'address' : 'input', 'El enlace de ubicación no es válido o no es compatible.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'address.string' => 'La dirección debe ser una cadena de texto.',
            'address.max' => 'La dirección no puede superar los 1000 caracteres.',
            'input.string' => 'El input debe ser una cadena de texto.',
            'input.max' => 'El input no puede superar los 1000 caracteres.',
        ];
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

    public function getLocationInput(): string
    {
        $value = $this->input('address') ?? $this->input('input') ?? '';

        return is_string($value) ? $value : '';
    }

    private function looksLikeUrl(string $value): bool
    {
        $value = trim($value);

        return str_starts_with($value, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1;
    }

    private function isSupportedMapsUrl(string $value): bool
    {
        return GoogleMapsLinkResolver::isSupportedUrl($value)
            || AppleMapsLinkResolver::isSupportedUrl($value);
    }
}
