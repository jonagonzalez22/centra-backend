<?php

namespace App\Services;

use App\Models\CustomerCounter;
use Illuminate\Support\Facades\DB;

class CustomerCodeGeneratorService
{
    public function generate(string $storeId): string
    {
        return DB::transaction(function () use ($storeId) {
            $counter = CustomerCounter::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if ($counter) {
                $counter->last_number++;
                $counter->save();
            } else {
                $counter = CustomerCounter::create([
                    'store_id' => $storeId,
                    'last_number' => 1,
                ]);
            }

            return 'C-'.str_pad($counter->last_number, 6, '0', STR_PAD_LEFT);
        });
    }
}
