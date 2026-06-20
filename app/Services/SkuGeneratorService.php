<?php

namespace App\Services;

use App\Models\Category;
use App\Models\SkuCounter;
use Illuminate\Support\Facades\DB;

class SkuGeneratorService
{
    public function generate(string $storeId, ?string $categoryId, ?string $name): string
    {
        return DB::transaction(function () use ($storeId, $categoryId, $name) {
            $prefix = $this->determinePrefix($storeId, $categoryId, $name);

            $counter = SkuCounter::where('store_id', $storeId)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($counter) {
                $counter->last_number++;
                $counter->save();
            } else {
                $counter = SkuCounter::create([
                    'store_id' => $storeId,
                    'prefix' => $prefix,
                    'last_number' => 1,
                ]);
            }

            return $this->formatSku($prefix, $counter->last_number);
        });
    }

    private function determinePrefix(string $storeId, ?string $categoryId, ?string $name): string
    {
        if ($categoryId) {
            $category = Category::forStore($storeId)->find($categoryId);
            if ($category) {
                return $this->normalizePrefix($category->name);
            }
        }

        if ($name) {
            return $this->normalizePrefix($name);
        }

        return 'PROD';
    }

    private function normalizePrefix(string $text): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $text);
        $clean = strtoupper(substr($clean, 0, 4));

        if (strlen($clean) < 3) {
            return 'PROD';
        }

        return str_pad($clean, 4, 'X');
    }

    private function formatSku(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad($number, 6, '0', STR_PAD_LEFT);
    }
}
