<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoadSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'route_id' => $this['route_id'],
            'status' => $this['status'],
            'operational_date' => $this['operational_date'],
            'by_product' => $this['by_product'],
            'by_stop' => $this['by_stop'],
            'total_items' => $this['total_items'],
        ];
    }
}
