<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'route_id' => $this['route_id'],
            'status' => $this['status'],
            'operational_date' => $this['operational_date'],
            'vehicle' => $this['vehicle'],
            'driver' => $this['driver'],
            'stops' => $this['stops'],
            'totals' => [
                'declared_amount' => $this['totals']['declared_amount'],
                'verified_amount' => $this['totals']['verified_amount'],
                'rejected_amount' => $this['totals']['rejected_amount'],
            ],
            'can_close' => $this['can_close'],
        ];
    }
}
