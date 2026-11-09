<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportsAnalyticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => $this['period'],
            'realized_sales_series' => $this->moneySeries($this['realized_sales_series'], ['pos_amount', 'orders_amount', 'total_amount']),
            'payments_series' => $this->moneySeries($this['payments_series'], ['collected_amount']),
            'orders_series' => $this['orders_series'],
            'payment_methods' => $this->moneySeries($this['payment_methods'], ['amount']),
            'top_products' => $this['top_products'],
        ];
    }

    private function moneySeries(array $rows, array $keys): array
    {
        return array_map(function (array $row) use ($keys): array {
            foreach ($keys as $key) {
                $row[$key] = (float) $row[$key];
            }

            return $row;
        }, $rows);
    }
}
