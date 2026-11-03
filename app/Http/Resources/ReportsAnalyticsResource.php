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
            'payments_series' => $this->moneySeries($this['payments_series'], ['collected_amount']),
            'orders_series' => $this['orders_series'],
            'payment_methods' => $this->moneySeries($this['payment_methods'], ['amount']),
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
