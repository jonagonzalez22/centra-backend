<?php

namespace App\Http\Requests\Api\V1\Store;

class UpdatePurchaseRequest extends StorePurchaseRequest
{
    public function rules(): array
    {
        return parent::rules();
    }
}
