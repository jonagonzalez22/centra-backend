<?php

namespace App\Http\Resources;

use App\Support\QuantityMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_item_id' => $this->purchase_item_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name_snapshot,
            'product_sku' => $this->product_sku_snapshot,
            'measurement_unit_code' => $this->measurement_unit_code_snapshot,
            'measurement_unit_symbol' => $this->measurement_unit_symbol_snapshot,
            'quantity_received' => QuantityMath::normalize($this->quantity_received),
        ];
    }
}
