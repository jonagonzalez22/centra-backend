<?php

namespace App\Http\Requests\Api\V1\Store;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'declared_amount' => ['required', 'numeric', 'min:0'],
            'declaration_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
