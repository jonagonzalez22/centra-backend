<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'product_name' => $this->product_name, 'product_sku' => $this->product_sku, 'supplier_code' => $this->supplier_code, 'supplier_product_name' => $this->supplier_product_name, 'measurement_unit_code' => $this->measurement_unit_code, 'measurement_unit_symbol' => $this->measurement_unit_symbol, 'quantity_ordered' => $this->quantity_ordered, 'quantity_received' => $this->quantity_received, 'quantity_pending' => $this->quantity_pending, 'unit_price' => $this->unit_price, 'subtotal' => $this->subtotal];
    }
}
