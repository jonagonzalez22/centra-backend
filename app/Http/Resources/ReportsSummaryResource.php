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
                'realized_sales' => $this->moneyMetrics($this['metrics']['realized_sales']),
                'gross_profit' => $this->moneyMetrics($this['metrics']['gross_profit']),
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
        foreach (['amount', 'average_ticket', 'sales_amount', 'known_cost_amount', 'margin_percentage'] as $key) {
            if (array_key_exists($key, $metrics) && $metrics[$key] !== null) {
                $metrics[$key] = (float) $metrics[$key];
            }
        }

        if (isset($metrics['channels'])) {
            foreach ($metrics['channels'] as &$channel) {
                if (is_array($channel) && isset($channel['amount'])) {
                    $channel['amount'] = (float) $channel['amount'];
                } elseif (is_string($channel) || is_numeric($channel)) {
                    $channel = (float) $channel;
                }
            }
            unset($channel);
        }

        return $metrics;
    }
}
