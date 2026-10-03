<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\QuantityMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteStopItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;

        return [
            'id' => $this->id,
            'route_stop_id' => $this->route_stop_id,
            'product_id' => $this->product_id,
            'product_name' => $product?->name,
            'quantity_planned' => QuantityMath::normalize($this->quantity_planned),
            'quantity_loaded' => QuantityMath::normalize($this->quantity_loaded),
            'quantity_delivered' => QuantityMath::normalize($this->quantity_delivered),
            'quantity_released_for_extra_sale' => QuantityMath::normalize($this->quantity_released_for_extra_sale),
            'sale_quantity_step' => QuantityMath::normalize($product?->sale_quantity_step ?? '1'),
            'stock_measurement_unit' => $product?->relationLoaded('stockMeasurementUnit')
                ? MeasurementUnitResource::make($product->stockMeasurementUnit)
                : null,
        ];
    }
}
