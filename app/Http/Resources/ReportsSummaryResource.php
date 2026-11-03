<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportsSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => $this['period'],
            'metrics' => [
                'pos_sales' => $this->moneyMetrics($this['metrics']['pos_sales']),
                'orders_taken' => $this->moneyMetrics($this['metrics']['orders_taken']),
                'deliveries_completed' => $this['metrics']['deliveries_completed'],
                'collected' => $this->moneyMetrics($this['metrics']['collected']),
            ],
            'current' => [
                'pending_balance' => $this->moneyMetrics($this['current']['pending_balance']),
                'inventory_alerts' => $this['current']['inventory_alerts'],
                'attention' => $this['current']['attention'],
            ],
        ];
    }

    private function moneyMetrics(array $metrics): array
    {
        foreach (['amount', 'average_ticket'] as $key) {
            if (array_key_exists($key, $metrics)) {
                $metrics[$key] = (float) $metrics[$key];
            }
        }

        return $metrics;
    }
}
